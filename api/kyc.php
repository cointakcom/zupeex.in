<?php
/**
 * ======================================================
 * KYC.PHP - User-Facing KYC Submission API (CSRF FIXED)
 * Ludo Tournament Platform - Aadhaar/PAN/Bank Upload
 * Version: 1.2.0 - CSRF AUTO-REFRESH SUPPORT
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

SessionManager::init();

if (!isLoggedIn()) {
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid session', [], 401);
}

const KYC_UPLOAD_DIR = __DIR__ . '/../uploads/kyc';
const KYC_MAX_FILE_BYTES = 5 * 1024 * 1024;
const KYC_ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'submit': 
        handleSubmitDocument($userId); 
        break;
    case 'get_status': 
        handleGetStatus($userId); 
        break;
    case 'save_personal_details': 
        handleSavePersonalDetails($userId); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// SAVE PERSONAL DETAILS
// ==============================================
function handleSavePersonalDetails(int $userId) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid or expired CSRF token. Please refresh and try again.', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403);
    }

    $fullName = trim($input['full_name'] ?? '');
    $dob = trim($input['dob'] ?? '');
    $address = trim($input['address'] ?? '');
    $city = trim($input['city'] ?? '');
    $state = trim($input['state'] ?? '');
    $pincode = trim($input['pincode'] ?? '');

    if (mb_strlen($fullName) < 3 || !preg_match('/^[A-Za-z .]{3,100}$/', $fullName)) {
        jsonResponse(false, 'Full name must be 3-100 characters, letters and spaces only', [], 400);
    }
    if (!$dob || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        jsonResponse(false, 'Valid date of birth is required', [], 400);
    }
    $age = floor((time() - strtotime($dob)) / 31556952);
    if ($age < 18) {
        jsonResponse(false, 'You must be at least 18 years old to complete KYC', [], 400);
    }
    if (mb_strlen($address) < 5) { jsonResponse(false, 'Please enter a valid address', [], 400); }
    if (mb_strlen($city) < 2 || mb_strlen($state) < 2) { jsonResponse(false, 'City and State are required', [], 400); }
    if (!preg_match('/^\d{6}$/', $pincode)) { jsonResponse(false, 'PIN code must be exactly 6 digits', [], 400); }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("UPDATE users SET kyc_full_name = :name, kyc_dob = :dob, kyc_address = :addr, kyc_city = :city, kyc_state = :state, kyc_pincode = :pin, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([
            ':name' => $fullName, 
            ':dob' => $dob, 
            ':addr' => $address, 
            ':city' => $city, 
            ':state' => $state, 
            ':pin' => $pincode, 
            ':uid' => $userId
        ]);
        jsonResponse(true, 'Personal details saved', ['full_name' => $fullName]);
    } catch (Exception $e) {
        error_log('[KYC Personal Details Error] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred. Please try again.', [], 500);
    }
}

// ==============================================
// SUBMIT DOCUMENT
// ==============================================
function handleSubmitDocument(int $userId) {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid or expired CSRF token. Please refresh and try again.', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403);
    }

    $documentType = $_POST['document_type'] ?? '';
    if (!in_array($documentType, ['pan', 'aadhaar', 'bank'], true)) {
        jsonResponse(false, 'Invalid document type', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT id, status FROM kyc_documents WHERE user_id = :uid AND document_type = :type ORDER BY id DESC LIMIT 1");
        $stmt->execute([':uid' => $userId, ':type' => $documentType]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['status'] === 'verified') {
            jsonResponse(false, 'This document is already verified. Contact support to change it.', [], 409);
        }

        $db->beginTransaction();

        if ($documentType === 'bank') {
            $bankAccountNumber = trim($_POST['bank_account_number'] ?? '');
            $bankIfsc = strtoupper(trim($_POST['bank_ifsc'] ?? ''));
            $bankAccountName = trim($_POST['bank_account_name'] ?? '');
            $upiId = trim($_POST['upi_id'] ?? '');

            $hasBank = $bankAccountNumber && $bankIfsc && $bankAccountName;
            $hasUpi = !empty($upiId);
            if (!$hasBank && !$hasUpi) { 
                $db->rollback(); 
                jsonResponse(false, 'Provide either full bank account details or a UPI ID', [], 400); 
            }
            if ($hasBank && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $bankIfsc)) { 
                $db->rollback(); 
                jsonResponse(false, 'Invalid IFSC code format', [], 400); 
            }
            if ($hasUpi && !preg_match('/^[\w.\-]{2,49}@[a-zA-Z]{2,49}$/', $upiId)) { 
                $db->rollback(); 
                jsonResponse(false, 'Invalid UPI ID format', [], 400); 
            }

            $documentNumber = $hasBank ? $bankAccountNumber : $upiId;

            if ($existing) {
                $stmt = $conn->prepare("UPDATE kyc_documents SET document_number = :num, bank_account_number = :ban, bank_ifsc = :ifsc, bank_account_name = :bname, upi_id = :upi, status = 'pending', rejection_reason = NULL, verified_by = NULL, verified_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([
                    ':num' => $documentNumber, 
                    ':ban' => $hasBank ? $bankAccountNumber : null, 
                    ':ifsc' => $hasBank ? $bankIfsc : null, 
                    ':bname' => $hasBank ? $bankAccountName : null, 
                    ':upi' => $hasUpi ? $upiId : null, 
                    ':id' => $existing['id']
                ]);
            } else {
                $stmt = $conn->prepare("INSERT INTO kyc_documents (user_id, document_type, document_number, document_image_front, bank_account_number, bank_ifsc, bank_account_name, upi_id, status, created_at, updated_at) VALUES (:uid, 'bank', :num, 'n/a', :ban, :ifsc, :bname, :upi, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $stmt->execute([
                    ':uid' => $userId, 
                    ':num' => $documentNumber, 
                    ':ban' => $hasBank ? $bankAccountNumber : null, 
                    ':ifsc' => $hasBank ? $bankIfsc : null, 
                    ':bname' => $hasBank ? $bankAccountName : null, 
                    ':upi' => $hasUpi ? $upiId : null
                ]);
            }

            $db->commit();
            jsonResponse(true, 'Bank/UPI details submitted for verification', ['document_type' => 'bank', 'status' => 'pending']);
        } else {
            $stmt = $conn->prepare("SELECT kyc_full_name FROM users WHERE id = :uid");
            $stmt->execute([':uid' => $userId]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (empty($userRow['kyc_full_name'])) { 
                $db->rollback(); 
                jsonResponse(false, 'Please complete your personal details first', [], 400); 
            }

            $documentNumber = trim($_POST['document_number'] ?? '');
            if (!$documentNumber) { 
                $db->rollback(); 
                jsonResponse(false, 'Document number is required', [], 400); 
            }
            if ($documentType === 'pan' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper($documentNumber))) { 
                $db->rollback(); 
                jsonResponse(false, 'Invalid PAN number format', [], 400); 
            }
            if ($documentType === 'aadhaar' && !preg_match('/^\d{12}$/', $documentNumber)) { 
                $db->rollback(); 
                jsonResponse(false, 'Aadhaar number must be 12 digits', [], 400); 
            }

            if (empty($_FILES['document_image_front']) || $_FILES['document_image_front']['error'] !== UPLOAD_ERR_OK) { 
                $db->rollback(); 
                jsonResponse(false, 'Front image of the document is required', [], 400); 
            }

            $frontPath = saveKycUpload($_FILES['document_image_front'], $userId, $documentType . '_front');
            if (!$frontPath) { 
                $db->rollback(); 
                jsonResponse(false, 'Invalid image file. Only JPG/PNG/WebP under 5MB are allowed.', [], 400); 
            }

            $backPath = null;
            if (!empty($_FILES['document_image_back']) && $_FILES['document_image_back']['error'] === UPLOAD_ERR_OK) {
                $backPath = saveKycUpload($_FILES['document_image_back'], $userId, $documentType . '_back');
            }

            if ($existing) {
                $stmt = $conn->prepare("UPDATE kyc_documents SET document_number = :num, document_image_front = :front, document_image_back = :back, status = 'pending', rejection_reason = NULL, verified_by = NULL, verified_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':num' => strtoupper($documentNumber), ':front' => $frontPath, ':back' => $backPath, ':id' => $existing['id']]);
            } else {
                $stmt = $conn->prepare("INSERT INTO kyc_documents (user_id, document_type, document_number, document_image_front, document_image_back, status, created_at, updated_at) VALUES (:uid, :type, :num, :front, :back, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $stmt->execute([':uid' => $userId, ':type' => $documentType, ':num' => strtoupper($documentNumber), ':front' => $frontPath, ':back' => $backPath]);
            }

            $stmt = $conn->prepare("UPDATE users SET kyc_status = 'pending', updated_at = CURRENT_TIMESTAMP WHERE id = :uid AND kyc_status != 'verified'");
            $stmt->execute([':uid' => $userId]);

            $db->commit();
            jsonResponse(true, ucfirst($documentType) . ' submitted for verification', ['document_type' => $documentType, 'status' => 'pending']);
        }
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[KYC Submit Error] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred. Please try again.', [], 500);
    }
}

// ==============================================
// GET KYC STATUS
// ==============================================
function handleGetStatus(int $userId) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT kyc_status, is_verified, kyc_full_name, kyc_dob, kyc_address, kyc_city, kyc_state, kyc_pincode FROM users WHERE id = :uid");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT id, document_type, document_number, status, rejection_reason, bank_account_number, bank_ifsc, bank_account_name, upi_id, created_at, verified_at FROM kyc_documents WHERE user_id = :uid ORDER BY document_type");
        $stmt->execute([':uid' => $userId]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($docs as &$d) {
            if ($d['document_number']) {
                $len = strlen($d['document_number']);
                $d['document_number_masked'] = $len > 4 ? str_repeat('X', $len - 4) . substr($d['document_number'], -4) : str_repeat('X', $len);
            }
            unset($d['document_number']);
            if ($d['bank_account_number']) {
                $d['bank_account_number'] = str_repeat('X', max(0, strlen($d['bank_account_number']) - 4)) . substr($d['bank_account_number'], -4);
            }
        }
        unset($d);

        jsonResponse(true, 'KYC status retrieved', [
            'kyc_status' => $user['kyc_status'] ?? 'not_submitted',
            'is_verified' => boolval($user['is_verified'] ?? false),
            'personal_details' => [
                'full_name' => $user['kyc_full_name'] ?? '',
                'dob' => $user['kyc_dob'] ?? '',
                'address' => $user['kyc_address'] ?? '',
                'city' => $user['kyc_city'] ?? '',
                'state' => $user['kyc_state'] ?? '',
                'pincode' => $user['kyc_pincode'] ?? '',
                'completed' => !empty($user['kyc_full_name']),
            ],
            'documents' => $docs
        ]);
    } catch (Exception $e) {
        error_log('[KYC Status Error] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred.', [], 500);
    }
}

// ==============================================
// SAVE KYC UPLOAD
// ==============================================
function saveKycUpload(array $file, int $userId, string $label): ?string {
    if ($file['size'] > KYC_MAX_FILE_BYTES) return null;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset(KYC_ALLOWED_MIME[$mime])) return null;
    $ext = KYC_ALLOWED_MIME[$mime];

    $userDir = KYC_UPLOAD_DIR . '/' . $userId;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0750, true);
    }

    $filename = $label . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $userDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return null;
    }

    return $userId . '/' . $filename;
}
?>