<?php
/**
 * ======================================================
 * SETTLE.PHP - Match Settlement Engine (CSRF FIXED)
 * Ludo Tournament Platform - 15% Commission Processor
 * Version: 2.2.0 - CSRF AUTO-REFRESH SUPPORT
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

SessionManager::init();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { jsonResponse(false, 'Invalid JSON', [], 400); }

$required = ['match_id', 'winner_id'];
foreach ($required as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        jsonResponse(false, "Missing: {$field}", [], 400);
    }
}

$matchId = intval($input['match_id']);
$winnerId = intval($input['winner_id']);

if ($matchId <= 0 || $winnerId <= 0) { 
    jsonResponse(false, 'Invalid match or winner ID', [], 400); 
}

// 🔥 CSRF Validation with auto-refresh token
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!CSRFToken::validate($csrfToken)) { 
    jsonResponse(false, 'Invalid CSRF token', [
        'csrf_token' => CSRFToken::generate(),
        'refresh_needed' => true
    ], 403); 
}

if (!isLoggedIn()) { jsonResponse(false, 'Not authenticated', [], 401); }
$userId = getCurrentUserId();

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $db->beginTransaction();
    
    $stmt = $conn->prepare("SELECT * FROM matches WHERE id = :mid FOR UPDATE");
    $stmt->execute([':mid' => $matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$match) { $db->rollback(); jsonResponse(false, 'Match not found', [], 404); }
    
    if ($match['status'] === 'completed') {
        $db->rollback();
        jsonResponse(false, 'Match already settled', [
            'match_id' => $matchId, 
            'winner_id' => $match['winner_id'], 
            'winning_amount' => floatval($match['winning_amount'])
        ], 409);
    }
    
    if (!in_array($match['status'], ['playing', 'ready'])) { 
        $db->rollback(); 
        jsonResponse(false, 'Match not in playable state', [], 400); 
    }
    
    $playerIds = array_filter([
        intval($match['player1_id'] ?? 0), 
        intval($match['player2_id'] ?? 0),
        intval($match['player3_id'] ?? 0), 
        intval($match['player4_id'] ?? 0)
    ]);
    
    if (!in_array($winnerId, $playerIds)) { 
        $db->rollback(); 
        jsonResponse(false, 'Winner not in match', [], 400); 
    }
    
    // Authorization: winner ya admin hi settle kar sakta hai
    if ($userId != $winnerId) {
        $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = :uid AND is_admin = 1");
        $stmt->execute([':uid' => $userId]);
        if (!$stmt->fetch()) { 
            $db->rollback(); 
            jsonResponse(false, 'Unauthorized', [], 403); 
        }
    }
    
    $entryFee = floatval($match['entry_fee']);
    $totalPlayers = count($playerIds);
    $grossPool = $entryFee * $totalPlayers;
    $adminFee = round($grossPool * 0.15, 2);
    $netPayout = round($grossPool - $adminFee, 2);
    
    $stmt = $conn->prepare("SELECT id, username, wallet_balance, total_matches_played, total_matches_won, total_earnings, elo_rating FROM users WHERE id = :wid FOR UPDATE");
    $stmt->execute([':wid' => $winnerId]);
    $winner = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$winner) { $db->rollback(); jsonResponse(false, 'Winner not found', [], 404); }
    
    $currentBalance = floatval($winner['wallet_balance']);
    $newBalance = $currentBalance + $netPayout;
    
    $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt, total_matches_played = total_matches_played + 1, total_matches_won = total_matches_won + 1, total_earnings = total_earnings + :amt, updated_at = CURRENT_TIMESTAMP WHERE id = :wid");
    $stmt->execute([':amt' => $netPayout, ':wid' => $winnerId]);
    
    $loserIds = array_filter($playerIds, fn($id) => $id !== $winnerId);
    if (!empty($loserIds)) {
        $placeholders = implode(',', array_fill(0, count($loserIds), '?'));
        $stmt = $conn->prepare("UPDATE users SET total_matches_played = total_matches_played + 1, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($loserIds));
    }
    
    $orderId = 'WIN-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
    $stmt = $conn->prepare("INSERT INTO transactions (user_id, tournament_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, metadata, created_at) VALUES (:uid, :tid, :mid, :amt, 'credit', 'match_win', :desc, :oid, 'success', :bb, :ba, :meta, CURRENT_TIMESTAMP)");
    $stmt->execute([
        ':uid' => $winnerId, 
        ':tid' => $match['tournament_id'] ?? null, 
        ':mid' => $matchId, 
        ':amt' => $netPayout, 
        ':desc' => "Match win - Room: {$match['room_code']}", 
        ':oid' => $orderId, 
        ':bb' => $currentBalance, 
        ':ba' => $newBalance, 
        ':meta' => json_encode([
            'gross_pool' => $grossPool, 
            'admin_fee' => $adminFee, 
            'entry_fee' => $entryFee, 
            'players' => $totalPlayers
        ])
    ]);
    
    // Admin commission transaction
    $adminOrderId = 'ADMIN-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
    $stmt = $conn->prepare("INSERT INTO transactions (user_id, tournament_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, metadata, created_at) VALUES (1, :tid, :mid, :amt, 'credit', 'deposit', :desc, :oid, 'success', 0, :amt, :meta, CURRENT_TIMESTAMP)");
    $stmt->execute([
        ':tid' => $match['tournament_id'] ?? null, 
        ':mid' => $matchId, 
        ':amt' => $adminFee, 
        ':desc' => "Platform commission (15%) - Room: {$match['room_code']}", 
        ':oid' => $adminOrderId, 
        ':meta' => json_encode([
            'gross_pool' => $grossPool, 
            'admin_fee' => $adminFee, 
            'match_id' => $matchId, 
            'winner_id' => $winnerId
        ])
    ]);
    
    $stmt = $conn->prepare("UPDATE matches SET status = 'completed', winner_id = :wid, winner_name = :wname, winning_amount = :wamt, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :mid");
    $stmt->execute([
        ':wid' => $winnerId, 
        ':wname' => $winner['username'], 
        ':wamt' => $netPayout, 
        ':mid' => $matchId
    ]);
    
    // Update leaderboard
    $stmt = $conn->prepare("INSERT INTO leaderboard (user_id, username, elo_rating, matches_played, matches_won, total_earnings, last_updated) VALUES (:uid, :uname, :elo, 1, 1, :earn, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE elo_rating = elo_rating + 10, matches_played = matches_played + 1, matches_won = matches_won + 1, total_earnings = total_earnings + :earn, last_updated = CURRENT_TIMESTAMP");
    $stmt->execute([
        ':uid' => $winnerId, 
        ':uname' => $winner['username'], 
        ':elo' => intval($winner['elo_rating'] ?? 1200) + 10, 
        ':earn' => $netPayout
    ]);
    
    $db->commit();
    
    jsonResponse(true, 'Match settled successfully', [
        'match_id' => $matchId, 
        'room_code' => $match['room_code'],
        'winner_id' => $winnerId, 
        'winner_name' => $winner['username'],
        'gross_pool' => $grossPool, 
        'admin_fee' => $adminFee,
        'net_payout' => $netPayout, 
        'new_balance' => $newBalance
    ]);
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollback();
    error_log('[settle] PDO error: ' . $e->getMessage());
    jsonResponse(false, 'Database error', [], 500);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollback();
    error_log('[settle] Error: ' . $e->getMessage());
    jsonResponse(false, $e->getMessage(), [], 500);
}
?>