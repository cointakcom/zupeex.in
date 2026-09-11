<?php
/**
 * ======================================================
 * ADMIN_DEPOSITS.PHP - Deposit Listing API (FIXED)
 * Ludo Tournament Platform - Admin Deposit Management
 * Version: 1.2.0 - IMPROVED
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
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Sirf GET requests allow hain
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
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
    if (!$stmt->fetch()) jsonResponse(false, 'Unauthorized', [], 401);
} catch (Exception $e) {
    jsonResponse(false, 'Auth error', [], 500);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': 
        handleList($conn); 
        break;
    case 'get_stats': 
        handleStats($conn); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// LIST DEPOSITS
// ==============================================
function handleList($conn) {
    $status = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $limit = max(1, min(200, intval($_GET['limit'] ?? 50)));
    $offset = max(0, intval($_GET['offset'] ?? 0));

    try {
        $where = "t.source = 'deposit'";
        $params = [];
        
        if (!empty($status) && in_array($status, ['pending', 'success', 'failed'], true)) {
            $where .= " AND t.status = :status";
            $params[':status'] = $status;
        }
        
        if ($search !== '') {
            $where .= " AND (u.username LIKE :search OR u.mobile LIKE :search OR t.order_id LIKE :search OR t.gateway_transaction_id LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where .= " AND DATE(t.created_at) >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
        
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where .= " AND DATE(t.created_at) <= :date_to";
            $params[':date_to'] = $dateTo;
        }

        // Total count
        $stmt = $conn->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN users u ON t.user_id = u.id WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());

        // Fetch deposits
        $stmt = $conn->prepare("
            SELECT t.id, t.user_id, t.amount, t.status, t.order_id, t.gateway_transaction_id,
                   t.payment_gateway, t.created_at, t.processed_at, 
                   u.username, u.mobile, u.email
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.id
            WHERE {$where}
            ORDER BY t.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Deposits retrieved', [
            'deposits' => $deposits ?: [], 
            'total' => $total, 
            'limit' => $limit, 
            'offset' => $offset,
            'page' => $offset > 0 ? floor($offset / $limit) + 1 : 1,
            'total_pages' => $limit > 0 ? ceil($total / $limit) : 1
        ]);
    } catch (PDOException $e) {
        error_log('[admin_deposits list] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET DEPOSIT STATS
// ==============================================
function handleStats($conn) {
    try {
        $stats = [];
        
        // Status counts
        foreach (['pending', 'success', 'failed'] as $s) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM transactions WHERE source = 'deposit' AND status = :s");
            $stmt->execute([':s' => $s]);
            $stats[$s] = intval($stmt->fetchColumn());
        }
        
        // Total success amount
        $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE source = 'deposit' AND status = 'success'");
        $stats['total_success_amount'] = floatval($stmt->fetchColumn());
        
        // Today count
        $stmt = $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'deposit' AND status = 'success' AND DATE(created_at) = CURDATE()");
        $stats['today_count'] = intval($stmt->fetchColumn());
        
        // Today amount
        $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE source = 'deposit' AND status = 'success' AND DATE(created_at) = CURDATE()");
        $stats['today_amount'] = floatval($stmt->fetchColumn());
        
        // Today pending count
        $stmt = $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'deposit' AND status = 'pending' AND DATE(created_at) = CURDATE()");
        $stats['today_pending_count'] = intval($stmt->fetchColumn());
        
        // Today pending amount
        $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE source = 'deposit' AND status = 'pending' AND DATE(created_at) = CURDATE()");
        $stats['today_pending_amount'] = floatval($stmt->fetchColumn());
        
        jsonResponse(true, 'Stats retrieved', $stats);
    } catch (PDOException $e) {
        error_log('[admin_deposits stats] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>