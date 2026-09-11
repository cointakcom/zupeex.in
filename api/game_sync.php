<?php
/**
 * ======================================================
 * GAME_SYNC.PHP - Game State Sync Endpoint (CSRF FIXED)
 * Ludo Tournament Platform - Save/Load Game State
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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

SessionManager::init();

if (!isLoggedIn()) {
    jsonResponse(false, 'Not authenticated', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid session', [], 401);
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'save_state': 
        handleSaveState($userId); 
        break;
    case 'get_state': 
        handleGetState($userId); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// SAVE GAME STATE
// ==============================================
function handleSaveState(int $userId): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['match_id'])) { 
        jsonResponse(false, 'Missing match ID', [], 400); 
    }
    
    $matchId = intval($input['match_id']);
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    // 🔥 CSRF Validation with auto-refresh token
    if (!CSRFToken::validate($csrfToken)) { 
        jsonResponse(false, 'Invalid CSRF token', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403); 
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT id, player1_id, player2_id, player3_id, player4_id, status FROM matches WHERE id = :mid FOR UPDATE");
        $stmt->execute([':mid' => $matchId]);
        
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) { 
            $db->rollback(); 
            jsonResponse(false, 'Match not found', [], 404); 
        }
        
        // Check if user is in this match
        $playerIds = array_filter([
            intval($match['player1_id'] ?? 0),
            intval($match['player2_id'] ?? 0),
            intval($match['player3_id'] ?? 0),
            intval($match['player4_id'] ?? 0)
        ]);
        
        if (!in_array($userId, $playerIds)) { 
            $db->rollback(); 
            jsonResponse(false, 'Not authorized for this match', [], 403); 
        }
        
        $allowedStatuses = ['playing', 'paused'];
        $requestedStatus = $input['status'] ?? 'playing';
        $safeStatus = in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : 'playing';

        $requestedTurn = intval($input['current_turn'] ?? 0);
        $safeTurn = in_array($requestedTurn, $playerIds, true) ? $requestedTurn : intval($match['player1_id'] ?? 0);

        // Dynamic token update based on player count
        $updateFields = [
            'current_turn_id = :turn',
            'dice_value = :dice',
            'turn_number = :tnum',
            'status = :status',
            'updated_at = CURRENT_TIMESTAMP'
        ];
        $params = [
            ':turn' => $safeTurn,
            ':dice' => intval($input['dice_value'] ?? 0),
            ':tnum' => intval($input['turn_number'] ?? 0),
            ':status' => $safeStatus,
            ':mid' => $matchId
        ];
        
        // Add token fields for each player
        for ($i = 1; $i <= 4; $i++) {
            $playerId = intval($match["player{$i}_id"] ?? 0);
            if ($playerId > 0) {
                $tokens = $input["p{$i}_tokens"] ?? []; // Support p1_tokens, p2_tokens, p3_tokens, p4_tokens
                if (empty($tokens)) {
                    // Fallback to individual token fields
                    $tokens = [
                        $input["p{$i}_token1"] ?? -1,
                        $input["p{$i}_token2"] ?? -1,
                        $input["p{$i}_token3"] ?? -1,
                        $input["p{$i}_token4"] ?? -1
                    ];
                }
                
                $updateFields[] = "p{$i}_token1 = :p{$i}t1";
                $updateFields[] = "p{$i}_token2 = :p{$i}t2";
                $updateFields[] = "p{$i}_token3 = :p{$i}t3";
                $updateFields[] = "p{$i}_token4 = :p{$i}t4";
                $updateFields[] = "p{$i}_home_count = :p{$i}hc";
                
                $params[":p{$i}t1"] = intval($tokens[0] ?? -1);
                $params[":p{$i}t2"] = intval($tokens[1] ?? -1);
                $params[":p{$i}t3"] = intval($tokens[2] ?? -1);
                $params[":p{$i}t4"] = intval($tokens[3] ?? -1);
                $params[":p{$i}hc"] = intval($input["p{$i}_home_count"] ?? 0);
            }
        }

        $sql = "UPDATE matches SET " . implode(', ', $updateFields) . " WHERE id = :mid";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $db->commit();
        
        jsonResponse(true, 'Game state saved', [
            'match_id' => $matchId, 
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) { $db->rollback(); }
        error_log('[game_sync save] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET GAME STATE
// ==============================================
function handleGetState(int $userId): void
{
    $matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
    if ($matchId <= 0) { jsonResponse(false, 'Invalid match ID', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, status, current_turn_id, dice_value, turn_number, player1_id, player2_id, player3_id, player4_id, p1_token1, p1_token2, p1_token3, p1_token4, p1_home_count, p2_token1, p2_token2, p2_token3, p2_token4, p2_home_count, p3_token1, p3_token2, p3_token3, p3_token4, p3_home_count, p4_token1, p4_token2, p4_token3, p4_token4, p4_home_count FROM matches WHERE id = :mid");
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
            jsonResponse(false, 'Not authorized to view this match', [], 403); 
        }
        
        jsonResponse(true, 'Game state retrieved', ['match' => $match]);
        
    } catch (PDOException $e) {
        error_log('[game_sync get] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>