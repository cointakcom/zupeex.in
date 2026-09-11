<?php
/**
 * ======================================================
 * CASHFREE.PHP - Payment Gateway Integration (CSRF FIXED)
 * Version: 4.1.0 - FULLY WORKING WITH AUTO CSRF
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

$allowedOrigins = [
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
    rtrim(BASE_URL, '/'),
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array(rtrim($origin, '/'), $allowedOrigins) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: rtrim(BASE_URL, '/')));
} else {
    header('Access-Control-Allow-Origin: ' . rtrim(BASE_URL, '/'));
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 🔥 CSRF Validation ONLY for non-webhook POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_GET['action']) ? trim($_GET['action']) : '';
    if ($action !== 'webhook') {
        csrfMiddleware();
    }
}

$action = isset($_GET['action']) ? trim($_GET['action']) : (isset($_POST['action']) ? trim($_POST['action']) : '');

$validActions = ['create_order', 'webhook', 'verify_payment', 'get_order_status'];
if (!in_array($action, $validActions)) {
    jsonResponse(false, 'Invalid action specified', [], 400);
}

switch ($action) {
    case 'create_order': 
        handleCreateOrder(); 
        break;
    case 'webhook': 
        handleWebhook(); 
        break;
    case 'verify_payment': 
        handleVerifyPayment(); 
        break;
    case 'get_order_status': 
        handleGetOrderStatus(); 
        break;
}

// ==============================================
// CREATE ORDER
// ==============================================
function handleCreateOrder() {
    if (!isLoggedIn()) { 
        jsonResponse(false, 'User not authenticated', [], 401); 
    }
    
    $userId = getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) { 
        jsonResponse(false, 'Invalid JSON payload', [], 400); 
    }
    
    $required = ['amount', 'return_url'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') { 
            jsonResponse(false, "Missing required field: {$field}", [], 400); 
        }
    }
    
    $amount = floatval($input['amount'] ?? 0);
    if ($amount <= 0 || $amount > 1000000) { 
        jsonResponse(false, 'Invalid amount. Must be between ₹1 and ₹10,00,000', [], 400); 
    }
    
    $returnUrl = filter_var($input['return_url'], FILTER_VALIDATE_URL);
    if (!$returnUrl) { 
        jsonResponse(false, 'Invalid return URL', [], 400); 
    }
    
    $appId = defined('CASHFREE_APP_ID_CFG') ? CASHFREE_APP_ID_CFG : '';
    $secretKey = defined('CASHFREE_SECRET_KEY_CFG') ? CASHFREE_SECRET_KEY_CFG : '';
    $environment = defined('CASHFREE_ENV_CFG') ? CASHFREE_ENV_CFG : 'test';
    $apiUrl = $environment === 'production' ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
    
    if (empty($appId) || empty($secretKey)) { 
        jsonResponse(false, 'Payment gateway not configured', [], 500); 
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance FROM users WHERE id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) { 
            jsonResponse(false, 'User not found', [], 404); 
        }
        
        $orderId = 'LUDO-' . strtoupper(substr(uniqid(), -8)) . '-' . bin2hex(random_bytes(4));
        $customerName = trim($input['customer_name'] ?? $user['username']);
        $customerEmail = filter_var($input['customer_email'] ?? $user['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'customer@example.com';
        $customerPhone = preg_match('/^[0-9]{10}$/', $input['customer_phone'] ?? $user['mobile'] ?? '') ? ($input['customer_phone'] ?? $user['mobile']) : '9999999999';
        
        $payload = [
            'order_id' => $orderId,
            'order_amount' => floatval($amount),
            'order_currency' => 'INR',
            'order_note' => 'Zupeex Wallet Deposit',
            'customer_details' => [
                'customer_id' => (string)$userId,
                'customer_name' => substr($customerName, 0, 50),
                'customer_email' => substr($customerEmail, 0, 100),
                'customer_phone' => substr($customerPhone, 0, 20),
            ],
            'order_meta' => [
                'return_url' => $returnUrl . (strpos($returnUrl, '?') ? '&' : '?') . 'order_id=' . urlencode($orderId),
                'notify_url' => BASE_URL . '/api/cashfree.php?action=webhook',
                'payment_methods' => 'cc,dc,upi,netbanking',
            ],
            'order_expiry_time' => date('Y-m-d\TH:i:s\Z', strtotime('+30 minutes')),
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl . '/orders',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 
                'x-api-version: 2022-09-01', 
                'x-client-id: ' . $appId, 
                'x-client-secret: ' . $secretKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) { 
            error_log('[Cashfree] CURL error: ' . $curlError); 
            jsonResponse(false, 'Payment gateway connection error', [], 500); 
        }
        
        $responseData = json_decode($response, true);
        if ($httpCode !== 200 || !isset($responseData['order_id'])) { 
            error_log('[Cashfree] API error: ' . json_encode($responseData)); 
            jsonResponse(false, 'Payment order creation failed', [], 400); 
        }
        
        $paymentSessionId = $responseData['payment_session_id'] ?? '';
        
        $db->beginTransaction();
        try {
            $stmt = $conn->prepare("INSERT INTO transactions (
                user_id, amount, type, source, description, order_id, 
                status, balance_before, balance_after, payment_gateway, 
                gateway_transaction_id, metadata, created_at
            ) VALUES (
                :uid, :amount, 'credit', 'deposit', :desc, :oid, 
                'pending', :bal_before, :bal_after, 'cashfree', 
                :gtx, :meta, CURRENT_TIMESTAMP
            )");
            $stmt->execute([
                ':uid' => $userId, 
                ':amount' => $amount, 
                ':desc' => 'Wallet deposit via Cashfree', 
                ':oid' => $orderId, 
                ':bal_before' => floatval($user['wallet_balance'] ?? 0), 
                ':bal_after' => floatval($user['wallet_balance'] ?? 0), 
                ':gtx' => $paymentSessionId, 
                ':meta' => json_encode(['payment_session_id' => $paymentSessionId])
            ]);
            $transactionId = $conn->lastInsertId();
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
        $newCsrf = refreshCsrfToken();
        
        jsonResponse(true, 'Payment order created', [
            'order_id' => $orderId, 
            'payment_session_id' => $paymentSessionId, 
            'amount' => $amount, 
            'currency' => 'INR', 
            'redirect_url' => $responseData['payment_links']['web'] ?? '', 
            'transaction_id' => $transactionId ?? null,
            'csrf_token' => $newCsrf
        ]);
    } catch (Exception $e) {
        error_log('[Cashfree] Error: ' . $e->getMessage());
        jsonResponse(false, 'Error processing payment', [], 500);
    }
}

// ==============================================
// WEBHOOK
// ==============================================
function handleWebhook() {
    $rawInput = file_get_contents('php://input');
    $headers = getallheaders();
    
    $environment = defined('CASHFREE_ENV_CFG') ? CASHFREE_ENV_CFG : 'test';
    
    if ($environment === 'production') {
        $signature = $headers['X-Webhook-Signature'] ?? $headers['x-webhook-signature'] ?? '';
        $timestamp = $headers['X-Webhook-Timestamp'] ?? $headers['x-webhook-timestamp'] ?? '';
        if (!verifyWebhookSignature($rawInput, $signature, $timestamp)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }
    
    $payload = json_decode($rawInput, true);
    if (!$payload) { 
        http_response_code(400); 
        echo json_encode(['error' => 'Invalid payload']); 
        exit; 
    }
    
    $eventType = $payload['type'] ?? $payload['event'] ?? '';
    $data = $payload['data'] ?? $payload['order'] ?? [];
    
    if ($eventType === 'PAYMENT_SUCCESS' || $eventType === 'ORDER_PAID') {
        handlePaymentSuccess($data);
    } elseif ($eventType === 'PAYMENT_FAILED') {
        handlePaymentFailed($data);
    } else {
        http_response_code(200);
        echo json_encode(['status' => 'ignored']);
        exit;
    }
}

// ==============================================
// PAYMENT SUCCESS
// ==============================================
function handlePaymentSuccess($data) {
    $orderId = $data['order_id'] ?? $data['order']['order_id'] ?? '';
    $txnId = $data['txn_id'] ?? $data['transaction_id'] ?? '';
    
    if (empty($orderId)) { 
        http_response_code(400); 
        echo json_encode(['error' => 'Missing order ID']); 
        exit; 
    }
    
    $result = creditDepositIfConfirmed($orderId, $txnId);
    http_response_code($result['http_code']);
    echo json_encode($result['body']);
    exit;
}

// ==============================================
// CREDIT DEPOSIT IF CONFIRMED
// ==============================================
function creditDepositIfConfirmed(string $orderId, string $gatewayTxnId): array {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        $stmt = $conn->prepare("SELECT id, user_id, amount, status FROM transactions WHERE order_id = :oid LIMIT 1 FOR UPDATE");
        $stmt->execute([':oid' => $orderId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) { 
            $db->rollback(); 
            return ['http_code' => 404, 'body' => ['success' => false, 'status' => 'not_found', 'message' => 'Transaction not found']]; 
        }
        
        if ($transaction['status'] === 'success') { 
            $db->commit(); 
            return ['http_code' => 200, 'body' => ['success' => true, 'status' => 'already_processed']]; 
        }

        $txAmount = floatval($transaction['amount'] ?? 0);
        
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amount, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':amount' => $txAmount, ':uid' => $transaction['user_id']]);

        $stmt = $conn->prepare("UPDATE transactions SET status = 'success', gateway_transaction_id = :gtx, balance_after = (SELECT wallet_balance FROM users WHERE id = :uid), processed_at = CURRENT_TIMESTAMP WHERE id = :tid");
        $stmt->execute([':gtx' => $gatewayTxnId, ':uid' => $transaction['user_id'], ':tid' => $transaction['id']]);

        creditReferralRewardIfEligible($conn, $transaction['user_id'], $txAmount);

        $db->commit();
        return ['http_code' => 200, 'body' => ['success' => true, 'status' => 'processed', 'order_id' => $orderId, 'amount' => $txAmount]];
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[Cashfree] creditDepositIfConfirmed error: ' . $e->getMessage());
        return ['http_code' => 500, 'body' => ['success' => false, 'status' => 'error', 'message' => $e->getMessage()]];
    }
}

// ==============================================
// CREDIT REFERRAL REWARD
// ==============================================
function creditReferralRewardIfEligible(PDO $conn, int $depositingUserId, float $depositAmount): void {
    $stmt = $conn->prepare("SELECT id, referrer_id, total_deposited, reward_credited FROM referrals WHERE referred_user_id = :uid FOR UPDATE");
    $stmt->execute([':uid' => $depositingUserId]);
    $referral = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$referral || intval($referral['reward_credited']) === 1) { return; }

    $newTotal = round(floatval($referral['total_deposited']) + $depositAmount, 2);

    $stmt = $conn->prepare("UPDATE referrals SET total_deposited = :total WHERE id = :id");
    $stmt->execute([':total' => $newTotal, ':id' => $referral['id']]);

    $threshold = defined('REFERRAL_DEPOSIT_THRESHOLD') ? REFERRAL_DEPOSIT_THRESHOLD : 500;
    $rewardAmount = defined('REFERRAL_REWARD_AMOUNT') ? REFERRAL_REWARD_AMOUNT : 100;
    
    if ($newTotal < $threshold) { return; }

    $referrerId = intval($referral['referrer_id']);

    $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :rid FOR UPDATE");
    $stmt->execute([':rid' => $referrerId]);
    $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$referrer) return;

    $before = floatval($referrer['wallet_balance']);
    $after = $before + $rewardAmount;

    $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt, referral_earnings = COALESCE(referral_earnings, 0) + :amt, updated_at = CURRENT_TIMESTAMP WHERE id = :rid");
    $stmt->execute([':amt' => $rewardAmount, ':rid' => $referrerId]);

    $stmt = $conn->prepare("UPDATE referrals SET reward_credited = 1, reward_credited_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->execute([':id' => $referral['id']]);

    $orderId = 'REF-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
    $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at) VALUES (:rid, :amt, 'credit', 'referral_bonus', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)");
    $stmt->execute([
        ':rid' => $referrerId, 
        ':amt' => $rewardAmount, 
        ':desc' => 'Referral reward — referred user completed ₹' . number_format($threshold, 0) . ' deposits', 
        ':oid' => $orderId, 
        ':bb' => $before, 
        ':ba' => $after
    ]);
}

// ==============================================
// PAYMENT FAILED
// ==============================================
function handlePaymentFailed($data) {
    $orderId = $data['order_id'] ?? $data['order']['order_id'] ?? '';
    if (empty($orderId)) { 
        http_response_code(400); 
        echo json_encode(['error' => 'Missing order ID']); 
        exit; 
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("UPDATE transactions SET status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE order_id = :oid AND status = 'pending'");
        $stmt->execute([':oid' => $orderId]);
        http_response_code(200);
        echo json_encode(['status' => 'processed']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ==============================================
// VERIFY PAYMENT
// ==============================================
function handleVerifyPayment() {
    if (!isLoggedIn()) { 
        jsonResponse(false, 'Not authenticated', [], 401); 
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $orderId = trim($input['order_id'] ?? '');
    if (empty($orderId)) { 
        jsonResponse(false, 'Order ID required', [], 400); 
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, amount, status, gateway_transaction_id, balance_before, balance_after, created_at, processed_at FROM transactions WHERE order_id = :oid AND user_id = :uid LIMIT 1");
        $stmt->execute([':oid' => $orderId, ':uid' => getCurrentUserId()]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tx) { 
            jsonResponse(false, 'Transaction not found', [], 404); 
        }
        
        $newCsrf = refreshCsrfToken();
        
        jsonResponse(true, 'Transaction status', [
            'order_id' => $orderId, 
            'amount' => floatval($tx['amount']), 
            'status' => $tx['status'], 
            'gateway_txn_id' => $tx['gateway_transaction_id'], 
            'created_at' => $tx['created_at'], 
            'processed_at' => $tx['processed_at'],
            'csrf_token' => $newCsrf
        ]);
    } catch (Exception $e) {
        error_log('[Cashfree verify] ' . $e->getMessage());
        jsonResponse(false, 'Error', [], 500);
    }
}

// ==============================================
// GET ORDER STATUS
// ==============================================
function handleGetOrderStatus() {
    if (!isLoggedIn()) { 
        jsonResponse(false, 'Not authenticated', [], 401); 
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = trim($input['order_id'] ?? '');
    
    if (empty($orderId)) { 
        jsonResponse(false, 'Order ID required', [], 400); 
    }
    
    $appId = defined('CASHFREE_APP_ID_CFG') ? CASHFREE_APP_ID_CFG : '';
    $secretKey = defined('CASHFREE_SECRET_KEY_CFG') ? CASHFREE_SECRET_KEY_CFG : '';
    $environment = defined('CASHFREE_ENV_CFG') ? CASHFREE_ENV_CFG : 'test';
    $apiUrl = $environment === 'production' ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
    
    if (empty($appId) || empty($secretKey)) { 
        jsonResponse(false, 'Payment gateway not configured', [], 500); 
    }
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl . '/orders/' . urlencode($orderId),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 
                'x-api-version: 2022-09-01', 
                'x-client-id: ' . $appId, 
                'x-client-secret: ' . $secretKey
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) { 
            jsonResponse(false, 'Failed to fetch order status', [], 400); 
        }
        
        jsonResponse(true, 'Order status', json_decode($response, true));
    } catch (Exception $e) {
        error_log('[Cashfree status] ' . $e->getMessage());
        jsonResponse(false, 'Error', [], 500);
    }
}

// ==============================================
// VERIFY WEBHOOK SIGNATURE
// ==============================================
function verifyWebhookSignature($payload, $signature, $timestamp) {
    $environment = defined('CASHFREE_ENV_CFG') ? CASHFREE_ENV_CFG : 'test';
    if ($environment === 'test') return true;
    
    if (empty($signature) || empty($timestamp)) return false;
    
    $webhookTime = intval($timestamp);
    if (abs(time() - $webhookTime) > 300) return false;
    
    $secretKey = defined('CASHFREE_SECRET_KEY_CFG') ? CASHFREE_SECRET_KEY_CFG : '';
    $expectedSignature = hash_hmac('sha256', $payload . $timestamp, $secretKey);
    return hash_equals($expectedSignature, $signature);
}
?>