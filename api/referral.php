<?php
/**
 * ======================================================
 * REFERRAL.PHP - Refer & Earn API (FIXED)
 * Ludo Tournament Platform - Real referral stats/history
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

SessionManager::init();

if (!isLoggedIn()) {
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid session', [], 401);
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_info': 
            jsonResponse(true, 'Referral info', getReferralInfoData($userId)); 
            break;
        case 'get_stats': 
            jsonResponse(true, 'Referral stats', getReferralStatsData($userId)); 
            break;
        case 'get_history': 
            jsonResponse(true, 'Referral history', ['history' => getReferralHistoryData($userId)]); 
            break;
        case 'get_all':
            jsonResponse(true, 'Referral data', [
                'info' => getReferralInfoData($userId),
                'stats' => getReferralStatsData($userId),
                'history' => getReferralHistoryData($userId),
            ]);
            break;
        default: 
            jsonResponse(false, 'Invalid action', [], 400);
    }
} catch (PDOException $e) {
    error_log('[referral.php] ' . $e->getMessage());
    jsonResponse(false, 'Database error', [], 500);
}

// ==============================================
// GET REFERRAL INFO
// ==============================================
function getReferralInfoData(int $userId): array {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT refer_code FROM users WHERE id = :uid");
    $stmt->execute([':uid' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $code = $user['refer_code'] ?? '';
    $link = $code ? (rtrim(BASE_URL, '/') . '/?ref=' . urlencode($code)) : '';

    return [
        'referral_code' => $code,
        'referral_link' => $link,
        'reward_amount' => defined('REFERRAL_REWARD_AMOUNT') ? REFERRAL_REWARD_AMOUNT : 100,
        'deposit_threshold' => defined('REFERRAL_DEPOSIT_THRESHOLD') ? REFERRAL_DEPOSIT_THRESHOLD : 500,
        'signup_bonus' => defined('SIGNUP_BONUS') ? SIGNUP_BONUS : 5,
    ];
}

// ==============================================
// GET REFERRAL STATS
// ==============================================
function getReferralStatsData(int $userId): array {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id = :uid");
    $stmt->execute([':uid' => $userId]);
    $totalReferrals = intval($stmt->fetchColumn());

    $stmt = $conn->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id = :uid AND reward_credited = 1");
    $stmt->execute([':uid' => $userId]);
    $completedReferrals = intval($stmt->fetchColumn());

    $stmt = $conn->prepare("SELECT COALESCE(referral_earnings, 0) FROM users WHERE id = :uid");
    $stmt->execute([':uid' => $userId]);
    $totalEarned = floatval($stmt->fetchColumn());

    return [
        'total_referrals' => $totalReferrals,
        'completed_referrals' => $completedReferrals,
        'pending_referrals' => max(0, $totalReferrals - $completedReferrals),
        'total_earned' => $totalEarned,
    ];
}

// ==============================================
// GET REFERRAL HISTORY
// ==============================================
function getReferralHistoryData(int $userId): array {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT r.total_deposited, r.reward_credited, r.reward_credited_at, r.created_at,
               u.username, u.mobile
        FROM referrals r
        JOIN users u ON r.referred_user_id = u.id
        WHERE r.referrer_id = :uid
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $threshold = defined('REFERRAL_DEPOSIT_THRESHOLD') ? REFERRAL_DEPOSIT_THRESHOLD : 500;

    return array_map(function($r) use ($threshold) {
        $deposited = floatval($r['total_deposited'] ?? 0);
        
        $status = 'not_started';
        if (intval($r['reward_credited'] ?? 0) === 1) {
            $status = 'complete';
        } elseif ($deposited > 0) {
            $status = 'in_progress';
        }

        $uname = $r['username'] ?? '';
        $masked = strlen($uname) > 3 ? substr($uname, 0, 3) . '***' : $uname . '***';

        return [
            'username' => $masked,
            'signup_date' => $r['created_at'],
            'total_deposited' => $deposited,
            'threshold' => $threshold,
            'progress_percent' => $threshold > 0 ? min(100, round(($deposited / $threshold) * 100)) : 0,
            'status' => $status,
            'reward_credited_at' => $r['reward_credited_at'] ?? null,
        ];
    }, $rows);
}
?>