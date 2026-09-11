<?php
/**
 * ======================================================
 * AUTH.PHP - MASTER CSRF SYSTEM (FIXED - INCLUDE SAFE)
 * Version: 7.2.0 - FIXED FOR OTHER API FILES
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

if (!defined('SIGNUP_BONUS')) {
    define('SIGNUP_BONUS', 5);
}

// ==============================================
// JSON RESPONSE HELPER - DEFINED FIRST
// ==============================================
if (!function_exists('jsonResponse')) {
    function jsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200): void {
        http_response_code($statusCode);
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==============================================
// MASTER CSRF SYSTEM
// ==============================================
function generateCsrfToken(): string {
    if (!empty($_SESSION['csrf_token'])) {
        return $_SESSION['csrf_token'];
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    setcookie('XSRF-TOKEN', $token, [
        'expires' => time() + 7200,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
    return $token;
}

function validateCsrfToken(?string $token): bool {
    if (empty($token)) return false;
    if (empty($_SESSION['csrf_token'])) return false;
    if ($token !== $_SESSION['csrf_token']) return false;
    if (isset($_SESSION['csrf_token_time'])) {
        if ((time() - $_SESSION['csrf_token_time']) > 7200) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
    }
    return true;
}

function refreshCsrfToken(): string {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    setcookie('XSRF-TOKEN', $token, [
        'expires' => time() + 7200,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
    return $token;
}

function csrfMiddleware(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!validateCsrfToken($token)) {
            $newToken = refreshCsrfToken();
            jsonResponse(false, 'Invalid CSRF token. Please refresh and try again.', [
                'csrf_token' => $newToken,
                'refresh_needed' => true
            ], 403);
            exit;
        }
    }
}

// Ensure CSRF token exists
if (empty($_SESSION['csrf_token'])) {
    generateCsrfToken();
}

// ==============================================
// 🔥 CRITICAL FIX: Sirf DIRECT CALL par switch chalao
// Agar auth.php kisi aur file ne include kiya hai,
// to sirf functions available rahenge, switch NAHI chalega
// ==============================================
$calledFile = basename($_SERVER['SCRIPT_NAME'] ?? '');

if ($calledFile === 'auth.php') {
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

    $input = [];
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    try {
        switch ($action) {
            case 'get_csrf': 
                handleGetCsrf(); 
                break;
            case 'login': 
                handleLogin($input); 
                break;
            case 'register': 
                handleRegister($input); 
                break;
            case 'logout': 
                handleLogout($input); 
                break;
            case 'check': 
                handleCheckAuth(); 
                break;
            default: 
                jsonResponse(false, 'Invalid action', [], 400);
        }
    } catch (Throwable $e) {
        error_log('Auth API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        jsonResponse(false, 'Server error occurred. Please try again.', [], 500);
    }
}
// ✅ Agar include hua hai to yahan se functions available hain, switch nahi chalega

// ==============================================
// GET CSRF TOKEN
// ==============================================
function handleGetCsrf(): void {
    $token = generateCsrfToken();
    jsonResponse(true, 'CSRF token retrieved', ['csrf_token' => $token]);
}

// ==============================================
// LOGIN
// ==============================================
function handleLogin(array $input): void {
    try {
        $mobile = trim($input['mobile'] ?? $input['username'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($mobile)) { 
            jsonResponse(false, 'Mobile number is required', [], 400); 
        }
        if (strlen($password) < 6) { 
            jsonResponse(false, 'Password must be at least 6 characters', [], 400); 
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT id, username, mobile, email, password AS password_hash, is_active, is_verified, kyc_status, wallet_balance, total_matches_played, total_matches_won, total_earnings, elo_rating, refer_code, referral_earnings, failed_login_attempts FROM users WHERE mobile = :mobile LIMIT 1");
        $stmt->execute([':mobile' => $mobile]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) { 
            jsonResponse(false, 'Invalid mobile number or password', [], 401); 
        }
        if ($user['is_active'] != 1) { 
            jsonResponse(false, 'Account deactivated. Please contact support.', [], 403); 
        }

        $maxAttempts = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;
        if (intval($user['failed_login_attempts'] ?? 0) >= $maxAttempts) { 
            jsonResponse(false, 'Account locked. Contact support.', [], 403); 
        }

        if (!password_verify($password, $user['password_hash'])) {
            $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = COALESCE(failed_login_attempts, 0) + 1 WHERE id = :uid");
            $stmt->execute([':uid' => $user['id']]);
            $remaining = $maxAttempts - intval($user['failed_login_attempts'] ?? 0) - 1;
            $msg = 'Invalid mobile number or password';
            if ($remaining > 0) $msg .= ". {$remaining} attempt(s) remaining.";
            jsonResponse(false, $msg, [], 401);
        }

        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, last_login = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':uid' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['mobile'] = $user['mobile'];
        $_SESSION['logged_in'] = true;

        $newCsrf = refreshCsrfToken();

        $userClean = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'mobile' => $user['mobile'],
            'email' => $user['email'],
            'wallet_balance' => (float)($user['wallet_balance'] ?? 0),
            'total_matches_played' => (int)($user['total_matches_played'] ?? 0),
            'total_matches_won' => (int)($user['total_matches_won'] ?? 0),
            'total_earnings' => (float)($user['total_earnings'] ?? 0),
            'elo_rating' => (int)($user['elo_rating'] ?? 1200),
            'is_verified' => (bool)($user['is_verified'] ?? false),
            'kyc_status' => $user['kyc_status'] ?? 'not_submitted',
            'refer_code' => $user['refer_code'] ?? '',
            'referral_earnings' => (float)($user['referral_earnings'] ?? 0),
        ];

        jsonResponse(true, 'Login successful', ['user' => $userClean, 'csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        error_log('Auth Login PDO error: ' . $e->getMessage());
        jsonResponse(false, 'Database error. Please try again.', [], 500);
    } catch (Exception $e) {
        error_log('Auth Login error: ' . $e->getMessage());
        jsonResponse(false, 'Server error. Please try again.', [], 500);
    }
}

// ==============================================
// REGISTER
// ==============================================
function handleRegister(array $input): void {
    try {
        $username = trim($input['username'] ?? '');
        $mobile = trim($input['mobile'] ?? '');
        $password = $input['password'] ?? '';
        $referralCode = trim($input['referral_code'] ?? '');

        if (strlen($username) < 3 || strlen($username) > 50) { 
            jsonResponse(false, 'Username must be 3-50 characters', [], 400); 
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { 
            jsonResponse(false, 'Username: letters, numbers, underscores only', [], 400); 
        }
        if (!preg_match('/^[0-9]{10}$/', $mobile)) { 
            jsonResponse(false, 'Invalid mobile number (10 digits required)', [], 400); 
        }
        if (strlen($password) < 6) { 
            jsonResponse(false, 'Password must be at least 6 characters', [], 400); 
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = :mobile");
        $stmt->execute([':mobile' => $mobile]);
        if ($stmt->fetch()) { 
            $conn->rollBack(); 
            jsonResponse(false, 'Mobile number already registered', [], 409); 
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) { 
            $conn->rollBack(); 
            jsonResponse(false, 'Username already taken', [], 409); 
        }

        $referCode = 'REF' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $referredBy = null;
        if (!empty($referralCode)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE refer_code = :code");
            $stmt->execute([':code' => strtoupper($referralCode)]);
            $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$referrer) { 
                $conn->rollBack(); 
                jsonResponse(false, 'Invalid referral code', [], 400); 
            }
            $referredBy = (int)$referrer['id'];
        }

        $sql = "INSERT INTO users (
            username, mobile, password, refer_code, referred_by, 
            wallet_balance, is_verified, is_active, kyc_status, 
            created_at, updated_at
        ) VALUES (
            :username, :mobile, :password, :refer_code, :referred_by, 
            :wallet_balance, :is_verified, :is_active, :kyc_status, 
            :created_at, :updated_at
        )";

        $params = [
            ':username' => $username,
            ':mobile' => $mobile,
            ':password' => $passwordHash,
            ':refer_code' => $referCode,
            ':referred_by' => $referredBy,
            ':wallet_balance' => SIGNUP_BONUS,
            ':is_verified' => 0,
            ':is_active' => 1,
            ':kyc_status' => 'not_submitted',
            ':created_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s')
        ];

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $newUserId = (int)$conn->lastInsertId();

        if (SIGNUP_BONUS > 0) {
            $bonusOrderId = 'SIGNUP-' . strtoupper(uniqid());
            $stmt = $conn->prepare("INSERT INTO transactions (
                user_id, amount, type, source, description, order_id, 
                status, balance_before, balance_after, created_at
            ) VALUES (
                :uid, :amt, :type, :source, :desc, :oid, 
                :status, :balance_before, :balance_after, :created_at
            )");
            $stmt->execute([
                ':uid' => $newUserId,
                ':amt' => SIGNUP_BONUS,
                ':type' => 'credit',
                ':source' => 'bonus',
                ':desc' => 'Welcome signup bonus',
                ':oid' => $bonusOrderId,
                ':status' => 'success',
                ':balance_before' => 0,
                ':balance_after' => SIGNUP_BONUS,
                ':created_at' => date('Y-m-d H:i:s')
            ]);
        }

        if ($referredBy) {
            $stmt = $conn->prepare("INSERT INTO referrals (
                referrer_id, referred_user_id, total_deposited, 
                reward_credited, created_at
            ) VALUES (
                :rid, :uid, :total_deposited, :reward_credited, :created_at
            )");
            $stmt->execute([
                ':rid' => $referredBy,
                ':uid' => $newUserId,
                ':total_deposited' => 0,
                ':reward_credited' => 0,
                ':created_at' => date('Y-m-d H:i:s')
            ]);
        }

        $conn->commit();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $newUserId;
        $_SESSION['username'] = $username;
        $_SESSION['mobile'] = $mobile;
        $_SESSION['logged_in'] = true;

        $newCsrf = refreshCsrfToken();

        $userClean = [
            'id' => $newUserId,
            'username' => $username,
            'mobile' => $mobile,
            'email' => null,
            'wallet_balance' => (float)SIGNUP_BONUS,
            'total_matches_played' => 0,
            'total_matches_won' => 0,
            'total_earnings' => 0.00,
            'elo_rating' => 1200,
            'is_verified' => false,
            'kyc_status' => 'not_submitted',
            'refer_code' => $referCode,
            'referral_earnings' => 0.00,
        ];

        jsonResponse(true, 'Registration successful', ['user' => $userClean, 'csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        error_log('Auth Register PDO error: ' . $e->getMessage());
        jsonResponse(false, 'Registration failed. Please try again.', [], 500);
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        error_log('Auth Register error: ' . $e->getMessage());
        jsonResponse(false, 'Registration failed. Please try again.', [], 500);
    }
}

// ==============================================
// LOGOUT
// ==============================================
function handleLogout(array $input): void {
    try {
        if (session_status() === PHP_SESSION_NONE) { 
            session_start(); 
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        setcookie('XSRF-TOKEN', '', time() - 42000, '/');
        session_destroy();
        jsonResponse(true, 'Logged out successfully');
    } catch (Exception $e) {
        error_log('Auth Logout error: ' . $e->getMessage());
        jsonResponse(false, 'Error during logout', [], 500);
    }
}

// ==============================================
// CHECK AUTH
// ==============================================
function handleCheckAuth(): void {
    try {
        if (session_status() === PHP_SESSION_NONE) { 
            session_start(); 
        }
        
        $userId = $_SESSION['user_id'] ?? null;
        $loggedIn = $_SESSION['logged_in'] ?? false;
        
        if (!$userId || !$loggedIn) { 
            jsonResponse(true, 'Not authenticated', ['logged_in' => false]); 
            return;
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance, total_matches_played, total_matches_won, total_earnings, elo_rating, is_verified, kyc_status, is_active, refer_code, referral_earnings FROM users WHERE id = :uid");
        $stmt->execute([':uid' => (int)$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['is_active'] != 1) {
            $_SESSION = [];
            session_destroy();
            jsonResponse(true, 'User not found or inactive', ['logged_in' => false]);
            return;
        }

        $newCsrf = refreshCsrfToken();

        $userClean = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'mobile' => $user['mobile'],
            'email' => $user['email'],
            'wallet_balance' => (float)($user['wallet_balance'] ?? 0),
            'total_matches_played' => (int)($user['total_matches_played'] ?? 0),
            'total_matches_won' => (int)($user['total_matches_won'] ?? 0),
            'total_earnings' => (float)($user['total_earnings'] ?? 0),
            'elo_rating' => (int)($user['elo_rating'] ?? 1200),
            'is_verified' => (bool)($user['is_verified'] ?? false),
            'kyc_status' => $user['kyc_status'] ?? 'not_submitted',
            'refer_code' => $user['refer_code'] ?? '',
            'referral_earnings' => (float)($user['referral_earnings'] ?? 0),
        ];

        jsonResponse(true, 'Authenticated', [
            'logged_in' => true, 
            'user' => $userClean, 
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        error_log('Auth Check PDO error: ' . $e->getMessage());
        jsonResponse(false, 'Error checking authentication', [], 500);
    } catch (Exception $e) {
        error_log('Auth Check error: ' . $e->getMessage());
        jsonResponse(false, 'Error checking authentication', [], 500);
    }
}
?>