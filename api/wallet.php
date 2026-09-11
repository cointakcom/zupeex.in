<?php
/**
 * ======================================================
 * WALLET.PHP - Atomic Wallet Operations (CSRF FIXED)
 * Version: 3.1.0 - COMPLETE WITH AUTO-REFRESH
 * ======================================================
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$allowedOrigins = [
    rtrim(BASE_URL, '/'),
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array(rtrim($origin, '/'), $allowedOrigins) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: rtrim(BASE_URL, '/')));
} else {
    header('Access-Control-Allow-Origin: ' . rtrim(BASE_URL, '/'));
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 🔥 CSRF ONLY FOR POST REQUESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfMiddleware();
}

if (!isLoggedIn()) {
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();
if (!$userId || $userId <= 0) {
    jsonResponse(false, 'Invalid session', [], 401);
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($action === 'deposit') {
    jsonResponse(false, 'Direct deposits are not supported. Please use Add Money from the wallet page.', [], 410);
    return;
}

switch ($action) {
    case 'balance': 
        handleGetBalance($userId); 
        break;
    case 'withdraw': 
        handleWithdraw($userId, $input); 
        break;
    case 'history': 
        handleGetHistory($userId); 
        break;
    case 'get_withdrawals': 
        handleGetWithdrawals($userId); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// GET BALANCE
// ==============================================
function handleGetBalance(int $userId): void {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT wallet_balance, total_earnings, total_withdrawn, referral_earnings, is_active FROM users WHERE id = :uid");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) { 
            jsonResponse(false, 'User not found', [], 404); 
        }

        $newCsrf = refreshCsrfToken();

        jsonResponse(true, 'Balance retrieved', [
            'balance' => floatval($user['wallet_balance'] ?? 0),
            'total_earnings' => floatval($user['total_earnings'] ?? 0),
            'total_withdrawn' => floatval($user['total_withdrawn'] ?? 0),
            'referral_earnings' => floatval($user['referral_earnings'] ?? 0),
            'is_active' => boolval($user['is_active']),
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        error_log('Wallet balance error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// WITHDRAW
// ==============================================
function handleWithdraw(int $userId, array $input): void {
    $amount = floatval($input['amount'] ?? 0);

    if ($amount <= 0 || $amount > 100000) {
        jsonResponse(false, 'Amount must be between ₹1 and ₹1,00,000', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        $stmt = $conn->prepare("SELECT id, wallet_balance, kyc_status, is_active FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['is_active'] != 1) {
            $db->rollback();
            jsonResponse(false, 'User not found or inactive', [], 404);
        }

        if ($user['kyc_status'] !== 'verified') {
            $db->rollback();
            jsonResponse(false, 'KYC verification required for withdrawal', [], 403);
        }

        $stmt = $conn->prepare("SELECT bank_account_number, bank_ifsc, bank_account_name, upi_id FROM kyc_documents WHERE user_id = :uid AND document_type = 'bank' AND status = 'verified' ORDER BY verified_at DESC LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $boundAccount = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$boundAccount) {
            $db->rollback();
            jsonResponse(false, 'Please bind and verify a bank account or UPI ID before withdrawing', [], 403);
        }

        $bankAccountNumber = $boundAccount['bank_account_number'];
        $bankIfsc = $boundAccount['bank_ifsc'];
        $bankAccountName = $boundAccount['bank_account_name'];
        $upiId = $boundAccount['upi_id'];

        $currentBalance = floatval($user['wallet_balance'] ?? 0);

        if ($currentBalance < $amount) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [
                'balance' => $currentBalance,
                'required' => $amount,
                'shortfall' => round($amount - $currentBalance, 2)
            ], 400);
        }

        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amount, total_withdrawn = total_withdrawn + :amount, last_withdrawal_date = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :uid AND wallet_balance >= :amount");
        $stmt->execute([':amount' => $amount, ':uid' => $userId]);

        if ($stmt->rowCount() === 0) {
            $db->rollback();
            jsonResponse(false, 'Withdrawal failed - insufficient balance', [], 400);
        }

        $newBalance = $currentBalance - $amount;

        $stmt = $conn->prepare("INSERT INTO withdrawals (user_id, amount, bank_account_number, bank_ifsc, bank_account_name, upi_id, status, created_at, updated_at) VALUES (:uid, :amount, :acct, :ifsc, :name, :upi, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':uid' => $userId,
            ':amount' => $amount,
            ':acct' => $bankAccountNumber,
            ':ifsc' => $bankIfsc,
            ':name' => $bankAccountName,
            ':upi' => $upiId
        ]);

        $withdrawalId = $conn->lastInsertId();

        $orderId = 'WD-' . strtoupper(uniqid());
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, source, description, order_id, status, balance_before, balance_after, metadata, created_at) VALUES (:uid, :amount, 'debit', 'withdrawal', :desc, :oid, 'pending', :bal_before, :bal_after, :meta, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':uid' => $userId,
            ':amount' => $amount,
            ':desc' => "Withdrawal to bank: {$bankAccountNumber}",
            ':oid' => $orderId,
            ':bal_before' => $currentBalance,
            ':bal_after' => $newBalance,
            ':meta' => json_encode(['withdrawal_id' => $withdrawalId])
        ]);

        $db->commit();

        $newCsrf = refreshCsrfToken();

        jsonResponse(true, 'Withdrawal request submitted', [
            'withdrawal_id' => $withdrawalId,
            'amount' => $amount,
            'balance' => $newBalance,
            'status' => 'pending',
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('Wallet withdraw error: ' . $e->getMessage());
        jsonResponse(false, 'Withdrawal failed', [], 500);
    }
}

// ==============================================
// GET TRANSACTION HISTORY
// ==============================================
function handleGetHistory(int $userId): void {
    $limit = max(1, min(200, intval($_GET['limit'] ?? 20)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $type = $_GET['type'] ?? '';

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $where = "user_id = :uid";
        $params = [':uid' => $userId];

        if (!empty($type) && in_array($type, ['credit', 'debit'])) {
            $where .= " AND type = :type";
            $params[':type'] = $type;
        }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM transactions WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());

        $stmt = $conn->prepare("SELECT id, amount, type, source, description, order_id, status, balance_before, balance_after, tds_deducted, created_at, processed_at FROM transactions WHERE {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transactions as &$tx) {
            $tx['amount'] = floatval($tx['amount'] ?? 0);
            $tx['balance_before'] = floatval($tx['balance_before'] ?? 0);
            $tx['balance_after'] = floatval($tx['balance_after'] ?? 0);
            $tx['tds_deducted'] = floatval($tx['tds_deducted'] ?? 0);
        }
        unset($tx);

        $newCsrf = refreshCsrfToken();

        jsonResponse(true, 'Transaction history retrieved', [
            'transactions' => $transactions ?: [],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        error_log('Wallet history error: ' . $e->getMessage());
        jsonResponse(false, 'Error fetching history', [], 500);
    }
}

// ==============================================
// GET WITHDRAWAL HISTORY
// ==============================================
function handleGetWithdrawals(int $userId): void {
    $limit = max(1, min(50, intval($_GET['limit'] ?? 10)));
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, amount, status, bank_account_number, bank_ifsc, upi_id, rejection_reason, transaction_id, created_at, processed_at, completed_at FROM withdrawals WHERE user_id = :uid ORDER BY created_at DESC LIMIT {$limit}");
        $stmt->execute([':uid' => $userId]);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($withdrawals as &$wd) {
            $wd['amount'] = floatval($wd['amount'] ?? 0);
            // Mask bank account
            if (!empty($wd['bank_account_number'])) {
                $acctLen = strlen($wd['bank_account_number']);
                $wd['bank_account_number_masked'] = $acctLen > 4 ? str_repeat('X', $acctLen - 4) . substr($wd['bank_account_number'], -4) : str_repeat('X', $acctLen);
                unset($wd['bank_account_number']);
            }
        }
        unset($wd);
        
        $newCsrf = refreshCsrfToken();
        
        jsonResponse(true, 'Withdrawal history retrieved', [
            'withdrawals' => $withdrawals ?: [],
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        error_log('Wallet withdrawals error: ' . $e->getMessage());
        jsonResponse(false, 'Error fetching withdrawals', [], 500);
    }
}
?>