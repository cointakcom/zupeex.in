<?php
/**
 * ======================================================
 * DATABASE CONFIGURATION & CORE SECURITY - FIXED
 * Ludo Tournament Platform - Production Ready
 * Version: 6.3.0 - CSRF AUTO-REFRESH SUPPORT
 * ======================================================
 */

declare(strict_types=1);

// ======================================================
// ENVIRONMENT CONFIGURATION
// ======================================================
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $env = parse_ini_file($envFile);
} else {
    $env = [];
}

// ======================================================
// CONSTANTS DEFINITION
// ======================================================
define('ENVIRONMENT', $env['ENVIRONMENT'] ?? 'development');

// Database credentials
define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
define('DB_NAME', $env['DB_NAME'] ?? 'ludo_tournament');
define('DB_USER', $env['DB_USER'] ?? 'ludo_user');
define('DB_PASS', !empty($env['DB_PASS']) ? $env['DB_PASS'] : 'Aakashhunmine@8090');
define('DB_CHARSET', 'utf8mb4');

// Site configuration
define('BASE_URL', rtrim($env['BASE_URL'] ?? 'https://zupeex.in/', '/'));

// What the BROWSER connects to for the realtime companion server. Empty =
// same-origin (recommended: Nginx reverse-proxies /socket.io/ to the
// internal server, so the browser never needs to know its port). Only set
// this if the realtime server is reachable on its own subdomain/port
// instead. PHP's own server-to-server calls use WS_RELAY_URL below.
define('WS_RELAY_PUBLIC_URL', $env['WS_RELAY_PUBLIC_URL'] ?? '');
define('SITE_NAME', 'Zupeex');
define('ADMIN_EMAIL', $env['ADMIN_EMAIL'] ?? 'support@localhost.com');
define('TIMEZONE', 'Asia/Kolkata');
define('SESSION_TIMEOUT', (int)($env['SESSION_TIMEOUT'] ?? 1800));
define('MAX_LOGIN_ATTEMPTS', 5);
define('CSRF_TOKEN_LENGTH', 32);
define('PLATFORM_FEE', (float)($env['PLATFORM_FEE'] ?? 15));
define('TDS_RATE', (float)($env['TDS_RATE'] ?? 30));
define('TDS_THRESHOLD', (float)($env['TDS_THRESHOLD'] ?? 10000));
define('SIGNUP_BONUS', (float)($env['SIGNUP_BONUS'] ?? 5));
define('REFERRAL_REWARD_AMOUNT', (float)($env['REFERRAL_REWARD_AMOUNT'] ?? 100));
define('REFERRAL_DEPOSIT_THRESHOLD', (float)($env['REFERRAL_DEPOSIT_THRESHOLD'] ?? 500));

// WebSocket Relay Config
define('WS_RELAY_URL', $env['WS_RELAY_URL'] ?? '');
define('INTERNAL_NOTIFY_SECRET', $env['INTERNAL_NOTIFY_SECRET'] ?? '');

// Cashfree constants
define('CASHFREE_APP_ID_CFG', $env['CASHFREE_APP_ID'] ?? '');
define('CASHFREE_SECRET_KEY_CFG', $env['CASHFREE_SECRET_KEY'] ?? '');
define('CASHFREE_ENV_CFG', $env['CASHFREE_ENV'] ?? 'test');
define('CASHFREE_PAYOUT_CLIENT_ID_CFG', $env['CASHFREE_PAYOUT_CLIENT_ID'] ?? '');
define('CASHFREE_PAYOUT_CLIENT_SECRET_CFG', $env['CASHFREE_PAYOUT_CLIENT_SECRET'] ?? '');
define('CASHFREE_PAYOUT_ENV_CFG', $env['CASHFREE_PAYOUT_ENV'] ?? 'test');

// Ticket tiers
define('TICKET_AMOUNTS', [1, 2, 5, 10, 20, 30, 50, 100, 200, 300, 400, 500, 1000, 5000, 10000]);

date_default_timezone_set(TIMEZONE);

// ======================================================
// 🔥 FORCE HTTPS (application-level safety net)
// The project's .htaccess already has a "Force HTTPS" RewriteRule, but that
// only takes effect if the site is served by Apache/OpenLiteSpeed with
// .htaccess overrides enabled. aaPanel very often serves sites via Nginx
// instead — which ignores .htaccess files completely — or behind a reverse
// proxy that terminates SSL without ever setting $_SERVER['HTTPS'] for PHP.
// Either way the browser can end up stuck on http:// even with a valid SSL
// certificate installed. This check also looks at the standard
// X-Forwarded-Proto header so it still works correctly behind a proxy.
// ======================================================
$isLocalRequest = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
    || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false;
$isHttpsRequest = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

if (!$isLocalRequest && !$isHttpsRequest && php_sapi_name() !== 'cli' && !headers_sent()) {
    $redirectHost = $_SERVER['HTTP_HOST'] ?? parse_url(BASE_URL, PHP_URL_HOST);
    $redirectUri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $redirectHost . $redirectUri, true, 301);
    exit;
}

// ======================================================
// ERROR REPORTING
// ======================================================
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
}

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ======================================================
// DATABASE CLASS
// ======================================================
class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '" . DB_CHARSET . "' COLLATE 'utf8mb4_unicode_ci'",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            // 🔥 CRITICAL: Align MySQL's session clock with PHP's TIMEZONE (Asia/Kolkata).
            // Without this, NOW()/CURRENT_TIMESTAMP are written in the DB server's own
            // (often UTC) timezone, but PHP's strtotime()/date() assume every DATETIME
            // string is already IST — a silent 5.5-hour skew that makes match_ends_at /
            // turn_started_at comparisons think timers have already expired.
            // A fixed offset is used (not the named zone 'Asia/Kolkata') so this works
            // even on servers where the mysql.time_zone_name tables aren't loaded.
            $this->connection->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            die("Database Connection Failed: " . $e->getMessage());
        }
    }

    private function __clone() {}
    public function __wakeup() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->connection->prepare($sql);
            foreach ($params as $key => $value) {
                $type = $this->getParamType($value);
                $stmt->bindValue($key, $value, $type);
            }
            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            error_log('Query failed: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw $e;
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0)
    {
        return $this->query($sql, $params)->fetchColumn($column);
    }

    public function insert(string $table, array $data): int
    {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $sql = sprintf("INSERT INTO `%s` (`%s`) VALUES (%s)", $table, implode('`, `', $fields), $placeholders);
        $this->query($sql, $data);
        return (int)$this->connection->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $setParts = [];
        foreach ($data as $field => $value) {
            $setParts[] = "`{$field}` = :set_{$field}";
        }
        $whereParts = [];
        foreach ($where as $field => $value) {
            $whereParts[] = "`{$field}` = :where_{$field}";
        }
        $params = [];
        foreach ($data as $field => $value) {
            $params["set_{$field}"] = $value;
        }
        foreach ($where as $field => $value) {
            $params["where_{$field}"] = $value;
        }
        $sql = sprintf("UPDATE `%s` SET %s WHERE %s", $table, implode(', ', $setParts), implode(' AND ', $whereParts));
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    private function getParamType($value): int
    {
        if (is_int($value)) return PDO::PARAM_INT;
        if (is_bool($value)) return PDO::PARAM_BOOL;
        if (is_null($value)) return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }
}

// ======================================================
// SESSION MANAGEMENT
// ======================================================
class SessionManager
{
    public static function init(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        
        if (headers_sent()) {
            error_log("Warning: Headers already sent, cannot start session");
            return false;
        }
        
        $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']) || 
                       strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false;
        $secure = !$isLocalhost && isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800;
        
        session_set_cookie_params([
            'lifetime' => $timeout,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $isLocalhost ? 'Lax' : 'Strict'
        ]);
        
        session_name('LUDO_SESS_ID');
        $result = session_start();
        
        if (!isset($_SESSION['session_init_time'])) {
            $_SESSION['session_init_time'] = time();
            session_regenerate_id(true);
        }
        return $result;
    }

    public static function get(string $key, $default = null) 
    { 
        return $_SESSION[$key] ?? $default; 
    }
    
    public static function set(string $key, $value): void 
    { 
        $_SESSION[$key] = $value; 
    }
    
    public static function has(string $key): bool 
    { 
        return isset($_SESSION[$key]); 
    }
    
    public static function remove(string $key): void 
    { 
        unset($_SESSION[$key]); 
    }
    
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
    }
}

// ======================================================
// CSRF TOKEN - SINGLE SOURCE OF TRUTH
// ======================================================
class CSRFToken
{
    public static function generate(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function validate(?string $token): bool
    {
        if (empty($_SESSION['csrf_token']) || empty($token)) return false;
        
        if (isset($_SESSION['csrf_token_time'])) {
            if ((time() - $_SESSION['csrf_token_time']) > 3600) {
                unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
                return false;
            }
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function refresh(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
        $_SESSION['csrf_token_time'] = time();
        return $_SESSION['csrf_token'];
    }
    
    public static function getHTMLField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::generate(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

// ======================================================
// UTILITY FUNCTIONS
// ======================================================
function sanitizeInput($data) 
{ 
    if (is_array($data)) return array_map('sanitizeInput', $data); 
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8'); 
}

function generateRandomString(int $length = 10): string 
{ 
    return bin2hex(random_bytes($length)); 
}

function generateRoomCode(): string 
{ 
    return strtoupper(substr(generateRandomString(4), 0, 6)); 
}

function generateReferralCode(): string 
{ 
    return 'REF' . strtoupper(substr(generateRandomString(4), 0, 8)); 
}

function formatCurrency(float $amount): string 
{ 
    return '₹' . number_format($amount, 2); 
}

function calculatePlatformFee(float $amount): float 
{ 
    return round($amount * (PLATFORM_FEE / 100), 2); 
}

function calculatePrizePool(float $entryFee, int $players): float 
{ 
    $total = $entryFee * $players; 
    $fee = calculatePlatformFee($total); 
    return round($total - $fee, 2); 
}

/**
 * Match-length timer for Tournament/Custom rooms (api/match.php + invite.php),
 * distinct from Speed Ludo ticket rooms which use each ticket's own
 * configurable duration_minutes. 1vs1 = 5 minutes, 1vs4 = 10 minutes.
 */
function getDefaultMatchDurationMinutes(string $gameMode): int
{
    return $gameMode === '1vs4' ? 10 : 5;
}

function isValidTicketAmount(float $amount): bool
{
    foreach (TICKET_AMOUNTS as $t) {
        if (abs($t - $amount) < 0.001) return true;
    }
    return false;
}

function allocateColors(string $gameMode): array
{
    if ($gameMode === '1vs1') {
        return [1 => 1, 2 => 3];
    }
    return [1 => 1, 2 => 2, 3 => 3, 4 => 4];
}

function calculateTop100Payout(int $totalParticipants, float $entryFee): array
{
    $totalPool = $totalParticipants * $entryFee;
    $numWinners = min(100, $totalParticipants);
    $tier11to100Count = max(0, min(90, $numWinners - 10));
    $tier4to10Count = max(0, min(7, $numWinners - 3));
    $tier11to100EachNominal = $entryFee * 4;
    $tier4to10EachNominal = $entryFee * 20;
    $flatTotalNominal = ($tier11to100Count * $tier11to100EachNominal) + ($tier4to10Count * $tier4to10EachNominal);
    $flatBudget = $totalPool * 0.7;
    $scaleFactor = 1.0;
    $scaled = false;
    if ($flatTotalNominal > $flatBudget && $flatTotalNominal > 0) { 
        $scaleFactor = $flatBudget / $flatTotalNominal; 
        $scaled = true; 
    }
    $tier11to100Each = round($tier11to100EachNominal * $scaleFactor, 2);
    $tier4to10Each = round($tier4to10EachNominal * $scaleFactor, 2);
    $subtotal11to100 = $tier11to100Count * $tier11to100Each;
    $remaining1 = $totalPool - $subtotal11to100;
    $payoutRank1 = $numWinners >= 1 ? round($remaining1 * 0.30, 2) : 0;
    $remaining2 = $remaining1 - $payoutRank1;
    $payoutRank2 = $numWinners >= 2 ? round($remaining2 * 0.15, 2) : 0;
    $remaining3 = $remaining2 - $payoutRank2;
    $payoutRank3 = $numWinners >= 3 ? round($remaining3 * 0.07, 2) : 0;
    $remaining4 = $remaining3 - $payoutRank3;
    $subtotal4to10 = $tier4to10Count * $tier4to10Each;
    $remaining5 = $remaining4 - $subtotal4to10;
    if ($remaining5 < -0.01) return ['error' => 'Payout formula would exceed the total pool even after scaling. Aborting distribution.'];
    $payouts = [];
    if ($numWinners >= 1) $payouts[1] = $payoutRank1;
    if ($numWinners >= 2) $payouts[2] = $payoutRank2;
    if ($numWinners >= 3) $payouts[3] = $payoutRank3;
    for ($r = 4; $r < 4 + $tier4to10Count; $r++) $payouts[$r] = $tier4to10Each;
    for ($r = 11; $r < 11 + $tier11to100Count; $r++) $payouts[$r] = $tier11to100Each;
    return [
        'payouts' => $payouts, 
        'admin_commission' => round(max(0, $remaining5), 2), 
        'total_pool' => $totalPool, 
        'scaled' => $scaled, 
        'scale_factor' => round($scaleFactor, 4),
        'nominal_tier_11_100_each' => $tier11to100EachNominal,
        'nominal_tier_4_10_each' => $tier4to10EachNominal
    ];
}

function notifyRoomViaWebSocket(string $roomCode): void
{
    if (empty(WS_RELAY_URL) || empty(INTERNAL_NOTIFY_SECRET)) return;
    if (empty($roomCode)) return;
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim(WS_RELAY_URL, '/') . '/internal/notify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Internal-Secret: ' . INTERNAL_NOTIFY_SECRET,
            ],
            CURLOPT_POSTFIELDS => json_encode(['room_code' => $roomCode]),
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[WS Notify] ' . $e->getMessage());
    }
}

// ======================================================
// JSON RESPONSE FUNCTION
// ======================================================
if (!function_exists('jsonResponse')) {
    function jsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        echo json_encode([
            'success' => $success, 
            'message' => $message, 
            'data' => $data, 
            'timestamp' => time()
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ======================================================
// AUTHENTICATION HELPERS
// ======================================================
function isLoggedIn(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        SessionManager::init();
    }
    return !empty(SessionManager::get('user_id'));
}

function getCurrentUserId(): ?int
{
    if (session_status() === PHP_SESSION_NONE) {
        SessionManager::init();
    }
    $userId = SessionManager::get('user_id');
    return $userId ? (int)$userId : null;
}

function isAdminLoggedIn(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        SessionManager::init();
    }
    return !empty(SessionManager::get('admin_id'));
}

// ======================================================
// INITIALIZE SESSION
// ======================================================
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    SessionManager::init();
}
?>