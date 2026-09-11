<?php
/**
 * ======================================================
 * ADMIN_WITHDRAWALS.PHP - Withdrawal Management API (CSRF FIXED)
 * Ludo Tournament Platform - Admin Withdrawal System
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
    case 'approve': 
        $input = validateCsrfOrFail();
        handleApprove($conn, $input); 
        break;
    case 'reject': 
        $input = validateCsrfOrFail();
        handleReject($conn, $input); 
        break;
    case 'process': 
        $input = validateCsrfOrFail();
        handleProcess($conn, $input); 
        break;
    case 'complete': 
        $input = validateCsrfOrFail();
        handleComplete($conn, $input); 
        break;
    case 'get_stats': 
        handleStats($conn); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// LIST WITHDRAWALS
// ==============================================
function handleList($conn) {
    $status = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $limit = max(1, min(200, intval($_GET['limit'] ?? 50)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    
    try {
        $where = "1=1";
        $params = [];
        if (!empty($status)) { $where .= " AND w.status = :status"; $params[':status'] = $status; }
        if ($search !== '') { $where .= " AND (u.username LIKE :search OR u.mobile LIKE :search)"; $params[':search'] = '%' . $search . '%'; }
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $where .= " AND DATE(w.created_at) >= :date_from"; $params[':date_from'] = $dateFrom; }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) { $where .= " AND DATE(w.created_at) <= :date_to"; $params[':date_to'] = $dateTo; }
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals w LEFT JOIN users u ON w.user_id = u.id WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("
            SELECT w.*, u.username, u.mobile, u.email, u.wallet_balance,
                   u.kyc_status, u.total_earnings, u.total_withdrawn
            FROM withdrawals w
            LEFT JOIN users u ON w.user_id = u.id
            WHERE {$where}
            ORDER BY CASE w.status WHEN 'pending' THEN 1 WHEN 'processing' THEN 2 ELSE 3 END,
                     w.created_at ASC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Withdrawals retrieved', [
            'withdrawals' => $withdrawals ?: [], 
            'total' => $total, 
            'limit' => $limit, 
            'offset' => $offset
        ]);
    } catch (PDOException $e) {
        error_log('[admin_withdrawals list] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET SINGLE WITHDRAWAL
// ==============================================
function handleGet($conn) {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Invalid ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("
            SELECT w.*, u.username, u.mobile, u.email, u.wallet_balance,
                   u.kyc_status, u.total_earnings, u.total_withdrawn,
                   a.username as processed_by_name
            FROM withdrawals w
            LEFT JOIN users u ON w.user_id = u.id
            LEFT JOIN users a ON w.processed_by = a.id
            WHERE w.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $wd = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wd) { jsonResponse(false, 'Not found', [], 404); }
        jsonResponse(true, 'Withdrawal retrieved', $wd);
    } catch (PDOException $e) {
        error_log('[admin_withdrawals get] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// APPROVE WITHDRAWAL
// ==============================================
function handleApprove($conn, $input) {
    $id = intval($input['id'] ?? 0);
    $notes = $input['notes'] ?? '';
    
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("SELECT user_id, amount, status, bank_account_number, bank_ifsc, bank_account_name, upi_id FROM withdrawals WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $wd = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wd || $wd['status'] !== 'pending') { 
            jsonResponse(false, 'Withdrawal not found or not pending', [], 400); 
        }
        
        $stmt = $conn->prepare("UPDATE users SET total_withdrawn = total_withdrawn + :amount, last_withdrawal_date = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':amount' => $wd['amount'], ':uid' => $wd['user_id']]);
        
        $txnId = 'WD-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
        
        $stmt = $conn->prepare("UPDATE withdrawals SET status = 'approved', processed_by = :admin_id, processed_at = CURRENT_TIMESTAMP, transaction_id = :txn, admin_notes = CONCAT(COALESCE(admin_notes,''), '\nApproved: ', :notes), updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([
            ':admin_id' => $_SESSION['admin_id'], 
            ':txn' => $txnId, 
            ':notes' => $notes, 
            ':id' => $id
        ]);
        
        jsonResponse(true, 'Withdrawal approved', [
            'transaction_id' => $txnId, 
            'auto_payout' => false
        ]);
    } catch (PDOException $e) {
        error_log('[admin_withdrawals approve] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// REJECT WITHDRAWAL
// ==============================================
function handleReject($conn, $input) {
    $id = intval($input['id'] ?? 0);
    $reason = $input['reason'] ?? 'Withdrawal rejected';
    
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("SELECT user_id, amount, status FROM withdrawals WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $wd = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wd || $wd['status'] !== 'pending') { 
            jsonResponse(false, 'Only pending withdrawals can be rejected', [], 400); 
        }
        
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid");
        $stmt->execute([':amt' => $wd['amount'], ':uid' => $wd['user_id']]);
        
        $stmt = $conn->prepare("UPDATE withdrawals SET status = 'rejected', processed_by = :admin_id, processed_at = CURRENT_TIMESTAMP, rejection_reason = :reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':admin_id' => $_SESSION['admin_id'], ':reason' => $reason, ':id' => $id]);
        
        jsonResponse(true, 'Withdrawal rejected and refunded');
    } catch (PDOException $e) {
        error_log('[admin_withdrawals reject] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// PROCESS WITHDRAWAL
// ==============================================
function handleProcess($conn, $input) {
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("UPDATE withdrawals SET status = 'processing', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'approved'");
        $stmt->execute([':id' => $id]);
        
        if ($stmt->rowCount() === 0) { 
            jsonResponse(false, 'Withdrawal must be approved first', [], 400); 
        }
        
        jsonResponse(true, 'Marked as processing');
    } catch (PDOException $e) {
        error_log('[admin_withdrawals process] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// COMPLETE WITHDRAWAL
// ==============================================
function handleComplete($conn, $input) {
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("SELECT status FROM withdrawals WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $wd = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wd || !in_array($wd['status'], ['processing', 'approved'])) { 
            jsonResponse(false, 'Invalid status for completion', [], 400); 
        }
        
        $stmt = $conn->prepare("UPDATE withdrawals SET status = 'completed', completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        jsonResponse(true, 'Withdrawal completed');
    } catch (PDOException $e) {
        error_log('[admin_withdrawals complete] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET WITHDRAWAL STATS
// ==============================================
function handleStats($conn) {
    try {
        $stats = [];
        foreach (['pending', 'processing', 'approved', 'completed', 'rejected'] as $s) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals WHERE status = :s");
            $stmt->execute([':s' => $s]);
            $stats[$s] = intval($stmt->fetchColumn());
        }
        
        $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE status = 'pending'");
        $stats['total_pending_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE status IN ('approved','completed')");
        $stats['total_processed_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM withdrawals");
        $stats['total_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM withdrawals WHERE DATE(created_at) = CURDATE()");
        $stats['today'] = intval($stmt->fetchColumn());
        
        jsonResponse(true, 'Stats retrieved', $stats);
    } catch (PDOException $e) {
        error_log('[admin_withdrawals stats] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>