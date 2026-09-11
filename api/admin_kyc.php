<?php
/**
 * ======================================================
 * ADMIN_KYC.PHP - KYC Management API (CSRF FIXED)
 * Ludo Tournament Platform - KYC Verification System
 * Version: 3.2.0 - CSRF AUTO-REFRESH SUPPORT
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
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

SessionManager::init();

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
    jsonResponse(false, 'Unauthorized', [], 401);
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT u.id FROM users u
        JOIN sessions s ON u.id = s.user_id
        WHERE u.id = :aid AND u.is_admin = 1 AND u.is_active = 1
        AND s.session_token = :token AND s.is_active = 1 AND s.expires_at > NOW()
    ");
    $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
    if (!$stmt->fetch()) {
        jsonResponse(false, 'Unauthorized', [], 401);
    }
} catch (Exception $e) {
    jsonResponse(false, 'Auth error', [], 500);
}

// 🔥 CSRF Validation Helper for POST requests
function validateCsrfOrFail() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(false, 'Invalid JSON body', [], 400);
    }
    
    $providedToken = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    
    if (!$providedToken || !CSRFToken::validate($providedToken)) {
        jsonResponse(false, 'Invalid CSRF token', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403);
    }
    
    return $input;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': 
        handleList($conn); 
        break;
    case 'get': 
        handleGet($conn); 
        break;
    case 'verify': 
        $input = validateCsrfOrFail();
        handleVerify($conn, $input); 
        break;
    case 'reject': 
        $input = validateCsrfOrFail();
        handleReject($conn, $input); 
        break;
    case 'get_stats': 
        handleStats($conn); 
        break;
    case 'admin_update_document': 
        $input = validateCsrfOrFail();
        handleAdminUpdateDocument($conn, $input); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// RECOMPUTE OVERALL KYC STATUS
// ==============================================
function recomputeOverallKycStatus($conn, int $userId): void {
    $stmt = $conn->prepare("SELECT document_type, status FROM kyc_documents WHERE user_id = :uid AND document_type IN ('pan','aadhaar')");
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byType = [];
    foreach ($rows as $r) $byType[$r['document_type']] = $r['status'];

    $panStatus = $byType['pan'] ?? null;
    $aadhaarStatus = $byType['aadhaar'] ?? null;

    if ($panStatus === 'verified' && $aadhaarStatus === 'verified') {
        $overall = 'verified';
        $isVerified = 1;
    } elseif ($panStatus === 'rejected' || $aadhaarStatus === 'rejected') {
        $overall = 'rejected';
        $isVerified = 0;
    } elseif ($panStatus === 'pending' || $aadhaarStatus === 'pending') {
        $overall = 'pending';
        $isVerified = 0;
    } else {
        $overall = 'not_submitted';
        $isVerified = 0;
    }

    $stmt = $conn->prepare("UPDATE users SET kyc_status = :status, is_verified = :iv, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
    $stmt->execute([':status' => $overall, ':iv' => $isVerified, ':uid' => $userId]);
}

// ==============================================
// LIST KYC DOCUMENTS
// ==============================================
function handleList($conn) {
    $status = $_GET['status'] ?? '';
    $limit = max(1, min(200, intval($_GET['limit'] ?? 50)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
    
    try {
        $where = "1=1";
        $params = [];
        if (!empty($status)) { $where .= " AND k.status = :status"; $params[':status'] = $status; }
        if (!empty($search)) { $where .= " AND (u.username LIKE :search OR u.mobile LIKE :search OR k.document_number LIKE :search)"; $params[':search'] = $search; }
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM kyc_documents k LEFT JOIN users u ON k.user_id = u.id WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("
            SELECT k.*, u.username, u.mobile, u.email, u.wallet_balance,
                   u.total_earnings, u.total_matches_played,
                   u.kyc_full_name, u.kyc_dob, u.kyc_address, u.kyc_city, u.kyc_state, u.kyc_pincode
            FROM kyc_documents k
            LEFT JOIN users u ON k.user_id = u.id
            WHERE {$where}
            ORDER BY CASE k.status WHEN 'pending' THEN 1 ELSE 2 END, k.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'KYC documents retrieved', [
            'documents' => $documents ?: [], 
            'total' => $total, 
            'limit' => $limit, 
            'offset' => $offset
        ]);
    } catch (PDOException $e) {
        error_log('[admin_kyc list] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET SINGLE KYC DOCUMENT
// ==============================================
function handleGet($conn) {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Invalid ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("
            SELECT k.*, u.username, u.mobile, u.email, u.wallet_balance,
                   u.total_earnings, u.total_matches_played, u.total_matches_won,
                   u.created_at as user_joined_at
            FROM kyc_documents k
            LEFT JOIN users u ON k.user_id = u.id
            WHERE k.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doc) { jsonResponse(false, 'Not found', [], 404); }
        jsonResponse(true, 'KYC document retrieved', $doc);
    } catch (PDOException $e) {
        error_log('[admin_kyc get] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// VERIFY KYC
// ==============================================
function handleVerify($conn, $input) {
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("SELECT user_id, status, document_type, document_number FROM kyc_documents WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$kyc || $kyc['status'] !== 'pending') { 
            jsonResponse(false, 'KYC not found or already processed', [], 400); 
        }
        
        $stmt = $conn->prepare("UPDATE kyc_documents SET status = 'verified', verified_by = :admin_id, verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':admin_id' => $_SESSION['admin_id'], ':id' => $id]);
        
        recomputeOverallKycStatus($conn, $kyc['user_id']);
        
        if ($kyc['document_type'] === 'pan') {
            $stmt = $conn->prepare("UPDATE users SET pan_number = :num WHERE id = :uid");
            $stmt->execute([':num' => $kyc['document_number'], ':uid' => $kyc['user_id']]);
        } elseif ($kyc['document_type'] === 'aadhaar') {
            $stmt = $conn->prepare("UPDATE users SET aadhaar_number = :num WHERE id = :uid");
            $stmt->execute([':num' => $kyc['document_number'], ':uid' => $kyc['user_id']]);
        }
        
        jsonResponse(true, 'KYC verified successfully');
    } catch (PDOException $e) {
        error_log('[admin_kyc verify] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// REJECT KYC
// ==============================================
function handleReject($conn, $input) {
    $id = intval($input['id'] ?? 0);
    $reason = trim($input['reason'] ?? 'Document verification failed');
    
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    if (strlen($reason) < 10) { jsonResponse(false, 'Please provide detailed reason (min 10 chars)', [], 400); }
    
    try {
        $stmt = $conn->prepare("SELECT user_id, status, document_type FROM kyc_documents WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$kyc || $kyc['status'] !== 'pending') { 
            jsonResponse(false, 'KYC not found or already processed', [], 400); 
        }
        
        $stmt = $conn->prepare("UPDATE kyc_documents SET status = 'rejected', rejection_reason = :reason, verified_by = :admin_id, verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':reason' => $reason, ':admin_id' => $_SESSION['admin_id'], ':id' => $id]);
        
        if (in_array($kyc['document_type'], ['pan', 'aadhaar'], true)) {
            recomputeOverallKycStatus($conn, $kyc['user_id']);
        }
        
        jsonResponse(true, 'KYC rejected');
    } catch (PDOException $e) {
        error_log('[admin_kyc reject] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN UPDATE DOCUMENT
// ==============================================
function handleAdminUpdateDocument($conn, $input) {
    $id = intval($input['id'] ?? 0);
    $reverify = !empty($input['reverify']);
    
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("SELECT * FROM kyc_documents WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$kyc) { jsonResponse(false, 'Document not found', [], 404); }
        
        $fields = [];
        $params = [':id' => $id];
        
        if ($kyc['document_type'] === 'bank') {
            if (isset($input['bank_account_number'])) { 
                $fields[] = 'bank_account_number = :ban'; 
                $params[':ban'] = trim($input['bank_account_number']); 
            }
            if (isset($input['bank_ifsc'])) {
                $ifsc = strtoupper(trim($input['bank_ifsc']));
                if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) { 
                    jsonResponse(false, 'Invalid IFSC code format', [], 400); 
                }
                $fields[] = 'bank_ifsc = :ifsc'; 
                $params[':ifsc'] = $ifsc ?: null;
            }
            if (isset($input['bank_account_name'])) { 
                $fields[] = 'bank_account_name = :bname'; 
                $params[':bname'] = trim($input['bank_account_name']); 
            }
            if (isset($input['upi_id'])) { 
                $fields[] = 'upi_id = :upi'; 
                $params[':upi'] = trim($input['upi_id']) ?: null; 
            }
        } else {
            if (isset($input['document_number'])) {
                $num = strtoupper(trim($input['document_number']));
                if ($kyc['document_type'] === 'pan' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $num)) { 
                    jsonResponse(false, 'Invalid PAN number format', [], 400); 
                }
                if ($kyc['document_type'] === 'aadhaar' && !preg_match('/^\d{12}$/', $num)) { 
                    jsonResponse(false, 'Aadhaar number must be 12 digits', [], 400); 
                }
                $fields[] = 'document_number = :num'; 
                $params[':num'] = $num;
            }
        }
        
        if (empty($fields)) { jsonResponse(false, 'No fields to update', [], 400); }
        
        $newStatus = $reverify ? 'verified' : 'pending';
        $fields[] = 'status = :status';
        $params[':status'] = $newStatus;
        if ($reverify) { 
            $fields[] = 'verified_by = :admin_id'; 
            $fields[] = 'verified_at = CURRENT_TIMESTAMP'; 
            $params[':admin_id'] = $_SESSION['admin_id']; 
        }
        $fields[] = 'updated_at = CURRENT_TIMESTAMP';
        
        $stmt = $conn->prepare("UPDATE kyc_documents SET " . implode(', ', $fields) . " WHERE id = :id");
        $stmt->execute($params);
        
        if (in_array($kyc['document_type'], ['pan', 'aadhaar'], true)) {
            recomputeOverallKycStatus($conn, intval($kyc['user_id']));
        }
        
        jsonResponse(true, 'Document corrected', ['status' => $newStatus]);
    } catch (Exception $e) {
        error_log('[Admin KYC Correction Error] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred.', [], 500);
    }
}

// ==============================================
// GET KYC STATS
// ==============================================
function handleStats($conn) {
    try {
        $stats = [];
        foreach (['pending', 'verified', 'rejected'] as $s) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM kyc_documents WHERE status = :s");
            $stmt->execute([':s' => $s]);
            $stats[$s] = intval($stmt->fetchColumn());
        }
        
        $stmt = $conn->query("SELECT COUNT(*) FROM kyc_documents");
        $stats['total'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(DISTINCT user_id) FROM kyc_documents WHERE status IN ('pending','verified','rejected')");
        $stats['total_submitted'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM kyc_documents WHERE DATE(created_at) = CURDATE()");
        $stats['today_submissions'] = intval($stmt->fetchColumn());
        
        jsonResponse(true, 'KYC stats retrieved', $stats);
    } catch (PDOException $e) {
        error_log('[admin_kyc stats] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>