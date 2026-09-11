<?php
/**
 * ======================================================
 * GAME_STATE.PHP - Real-time Game State Sync (FIXED)
 * Ludo Tournament Platform - Polling Endpoint
 * Version: 2.2.0 - IMPROVED
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

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    $matchId = intval($_GET['match_id'] ?? 0);
    $lastSync = intval($_GET['last_sync'] ?? 0);
} else {
    $matchId = intval($input['match_id'] ?? 0);
    $lastSync = intval($input['last_sync'] ?? 0);
}

if ($matchId <= 0) { jsonResponse(false, 'Invalid match ID', [], 400); }

if (!isLoggedIn()) { jsonResponse(false, 'Not authenticated', [], 401); }

$userId = getCurrentUserId();
if (!$userId) { jsonResponse(false, 'Invalid session', [], 401); }

session_write_close();

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT m.*, t.name AS tournament_name, t.status AS tournament_status
        FROM matches m
        LEFT JOIN tournaments t ON m.tournament_id = t.id
        WHERE m.id = :mid
    ");
    $stmt->execute([':mid' => $matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$match) { jsonResponse(false, 'Match not found', [], 404); }
    
    $playerIds = array_filter([
        intval($match['player1_id'] ?? 0), 
        intval($match['player2_id'] ?? 0),
        intval($match['player3_id'] ?? 0), 
        intval($match['player4_id'] ?? 0)
    ]);
    
    if (!in_array($userId, $playerIds)) {
        jsonResponse(false, 'Not authorized for this match', [], 403);
    }
    
    $userRole = null;
    if ($userId == $match['player1_id']) $userRole = 'player1';
    elseif ($userId == $match['player2_id']) $userRole = 'player2';
    elseif ($userId == ($match['player3_id'] ?? 0)) $userRole = 'player3';
    elseif ($userId == ($match['player4_id'] ?? 0)) $userRole = 'player4';
    
    $actions = [];
    if ($lastSync > 0) {
        $stmt = $conn->prepare("
            SELECT id, action_type, dice_value, token_number, from_position, to_position,
                   opponent_captured, created_at, UNIX_TIMESTAMP(created_at) as timestamp
            FROM game_actions WHERE match_id = :mid AND id > :last
            ORDER BY id ASC LIMIT 100
        ");
        $stmt->execute([':mid' => $matchId, ':last' => $lastSync]);
        $actions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    $players = [];
    if (!empty($playerIds)) {
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $stmt = $conn->prepare("SELECT id, username, mobile, wallet_balance, total_matches_played, total_matches_won, elo_rating, is_verified, is_active FROM users WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($playerIds));
        $userData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($userData as $u) $players[$u['id']] = $u;
    }
    
    $isMyTurn = (intval($match['current_turn_id'] ?? 0) === $userId);
    $canRoll = in_array($match['status'], ['playing', 'ready']) && $isMyTurn;
    
    $responseData = [
        'match' => [
            'id' => intval($match['id']),
            'room_code' => $match['room_code'],
            'tournament_id' => intval($match['tournament_id'] ?? 0),
            'tournament_name' => $match['tournament_name'],
            'entry_fee' => floatval($match['entry_fee']),
            'prize_pool' => floatval($match['prize_pool']),
            'status' => $match['status'],
            'current_turn_id' => intval($match['current_turn_id'] ?? 0),
            'current_turn_name' => getPlayerName($match, intval($match['current_turn_id'] ?? 0)),
            'dice_value' => intval($match['dice_value'] ?? 0),
            'turn_number' => intval($match['turn_number'] ?? 0),
            'max_turns' => intval($match['max_turns'] ?? 50),
            'started_at' => $match['started_at'],
            'completed_at' => $match['completed_at'],
            'created_at' => $match['created_at'],
            'updated_at' => $match['updated_at'],
            'is_my_turn' => $isMyTurn,
            'can_roll' => $canRoll,
        ],
        'players' => [
            'player1' => buildPlayerData($match, $players, 'player1', 1, $userId),
            'player2' => $match['player2_id'] ? buildPlayerData($match, $players, 'player2', 2, $userId) : null,
            'player3' => $match['player3_id'] ? buildPlayerData($match, $players, 'player3', 3, $userId) : null,
            'player4' => $match['player4_id'] ? buildPlayerData($match, $players, 'player4', 4, $userId) : null,
        ],
        'board' => [
            'p1_tokens' => [
                intval($match['p1_token1'] ?? -1), 
                intval($match['p1_token2'] ?? -1), 
                intval($match['p1_token3'] ?? -1), 
                intval($match['p1_token4'] ?? -1)
            ],
            'p1_home_count' => intval($match['p1_home_count'] ?? 0),
            'p2_tokens' => [
                intval($match['p2_token1'] ?? -1), 
                intval($match['p2_token2'] ?? -1), 
                intval($match['p2_token3'] ?? -1), 
                intval($match['p2_token4'] ?? -1)
            ],
            'p2_home_count' => intval($match['p2_home_count'] ?? 0),
        ],
        'winner' => $match['winner_id'] ? [
            'id' => intval($match['winner_id']), 
            'name' => $match['winner_name'], 
            'amount' => floatval($match['winning_amount']), 
            'is_me' => ($match['winner_id'] == $userId)
        ] : null,
        'actions' => $actions,
        'last_action_id' => !empty($actions) ? intval(end($actions)['id']) : null,
        'user' => [
            'id' => $userId, 
            'role' => $userRole, 
            'is_turn' => $isMyTurn, 
            'can_roll' => $canRoll
        ],
        'sync' => [
            'last_sync' => $lastSync, 
            'needs_full_sync' => ($lastSync === 0), 
            'actions_count' => count($actions)
        ],
    ];
    
    jsonResponse(true, 'Game state retrieved', $responseData);
    
} catch (PDOException $e) {
    error_log('[game_state] PDO error: ' . $e->getMessage());
    jsonResponse(false, 'Database error', [], 500);
} catch (Exception $e) {
    error_log('[game_state] Error: ' . $e->getMessage());
    jsonResponse(false, $e->getMessage(), [], 500);
}

// ==============================================
// HELPER FUNCTIONS
// ==============================================
function getPlayerName($match, $playerId) {
    if (!$playerId) return null;
    if ($playerId == $match['player1_id']) return $match['player1_name'];
    if ($playerId == $match['player2_id']) return $match['player2_name'];
    if ($playerId == ($match['player3_id'] ?? 0)) return $match['player3_name'];
    if ($playerId == ($match['player4_id'] ?? 0)) return $match['player4_name'];
    return 'Unknown';
}

function buildPlayerData($match, $players, $key, $num, $userId) {
    $playerId = intval($match[$key . '_id'] ?? 0);
    $playerName = $match[$key . '_name'] ?? '';
    return [
        'id' => $playerId,
        'name' => $playerName,
        'username' => $players[$playerId]['username'] ?? $playerName,
        'elo_rating' => intval($players[$playerId]['elo_rating'] ?? 1200),
        'is_verified' => boolval($players[$playerId]['is_verified'] ?? false),
        'is_me' => ($playerId === $userId),
    ];
}
?>