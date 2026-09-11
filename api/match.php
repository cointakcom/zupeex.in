<?php
/**
 * ======================================================
 * MATCH.PHP - Match Management API (CSRF FIXED)
 * Ludo Tournament Platform - Complete Match System
 * Version: 5.2.0 - CSRF AUTO-REFRESH SUPPORT
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
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

SessionManager::init();

if (!isLoggedIn()) {
    jsonResponse(false, 'Please login to join a match', [], 401);
}

$userId = getCurrentUserId();
if (!$userId || $userId <= 0) {
    jsonResponse(false, 'Invalid session', [], 401);
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'join': 
        handleJoinMatch($userId); 
        break;
    case 'get_active': 
        handleGetActiveMatch($userId); 
        break;
    case 'get_history': 
        handleGetMatchHistory($userId); 
        break;
    case 'get_room': 
        handleGetRoomDetails($userId); 
        break;
    case 'search': 
        handleSearchMatch($userId); 
        break;
    default: 
        jsonResponse(false, 'Invalid action specified', [], 400);
}

// ==============================================
// JOIN MATCH
// ==============================================
function handleJoinMatch(int $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { $input = $_POST; }
    
    // 🔥 CSRF Validation with auto-refresh token
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid or expired CSRF token. Please refresh the page and try again.', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403);
    }
    
    if (!isset($input['entry_fee']) || !isset($input['tournament_id'])) {
        jsonResponse(false, 'Entry fee and tournament ID required', [], 400);
    }
    
    $entryFee = floatval($input['entry_fee']);
    $tournamentId = intval($input['tournament_id']);
    $gameMode = in_array($input['game_mode'] ?? '1vs1', ['1vs1', '1vs4'], true) ? $input['game_mode'] : '1vs1';
    $maxPlayers = $gameMode === '1vs1' ? 2 : 4;
    
    if ($entryFee <= 0) {
        jsonResponse(false, 'Invalid entry fee', [], 400);
    }
    
    if ($tournamentId <= 0) {
        jsonResponse(false, 'Invalid tournament ID', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT id, username, wallet_balance, is_active, is_verified FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || $user['is_active'] != 1) { 
            $db->rollback(); 
            jsonResponse(false, 'User not found or inactive', [], 404); 
        }
        
        if ($user['wallet_balance'] < $entryFee) {
            $db->rollback();
            jsonResponse(false, 'Insufficient wallet balance', [
                'balance' => floatval($user['wallet_balance']), 
                'required' => $entryFee, 
                'shortfall' => round($entryFee - $user['wallet_balance'], 2)
            ], 400);
        }
        
        // Check if already in active match
        $stmt = $conn->prepare("SELECT id FROM matches WHERE (player1_id = :uid OR player2_id = :uid OR player3_id = :uid OR player4_id = :uid) AND status IN ('waiting', 'ready', 'playing')");
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetch()) { 
            $db->rollback(); 
            jsonResponse(false, 'You are already in an active match', [], 409); 
        }
        
        $roomCode = generateRoomCode();
        
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amount, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':amount' => $entryFee, ':uid' => $userId]);
        
        $platformFee = calculatePlatformFee($entryFee * $maxPlayers);
        $prizePool = calculatePrizePool($entryFee, $maxPlayers);
        
        // Look for existing waiting match
        $stmt = $conn->prepare("SELECT id, room_code, player1_id, player2_id, player3_id, player4_id FROM matches WHERE entry_fee = :fee AND game_mode = :mode AND status = 'waiting' AND player1_id != :uid AND (player2_id IS NULL OR player3_id IS NULL OR player4_id IS NULL) ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
        $stmt->execute([':fee' => $entryFee, ':mode' => $gameMode, ':uid' => $userId]);
        $existingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingMatch) {
            $seatField = null;
            if (empty($existingMatch['player2_id'])) $seatField = 2;
            elseif ($maxPlayers >= 3 && empty($existingMatch['player3_id'])) $seatField = 3;
            elseif ($maxPlayers >= 4 && empty($existingMatch['player4_id'])) $seatField = 4;

            if ($seatField === null) { 
                $db->rollback(); 
                jsonResponse(false, 'Match was already taken. Please try again.', [], 409); 
            }

            $matchId = $existingMatch['id'];
            
            // Count seated players
            $seatedCount = 0;
            for ($i = 1; $i <= 4; $i++) {
                if (!empty($existingMatch["player{$i}_id"])) $seatedCount++;
            }
            $seatedCount++; // Add current user
            
            $isNowFull = $seatedCount >= $maxPlayers;

            $updateFields = "player{$seatField}_id = :pid, player{$seatField}_name = :pname, updated_at = CURRENT_TIMESTAMP";
            $params = [':pid' => $userId, ':pname' => $user['username'], ':mid' => $matchId];

            if ($isNowFull) {
                $seatedIds = [];
                for ($i = 1; $i <= 4; $i++) {
                    if ($i == $seatField) {
                        $seatedIds[] = $userId;
                    } elseif (!empty($existingMatch["player{$i}_id"])) {
                        $seatedIds[] = intval($existingMatch["player{$i}_id"]);
                    }
                }
                $firstTurn = $seatedIds[array_rand($seatedIds)];

                $initialScores = [];
                for ($i = 1; $i <= $maxPlayers; $i++) { $initialScores["player{$i}"] = 0; }
                $durationMinutes = getDefaultMatchDurationMinutes($gameMode);

                $updateFields .= ", status = 'ready', current_turn_id = :turn, turn_started_at = CURRENT_TIMESTAMP, started_at = CURRENT_TIMESTAMP, player_colors = :colors, scores = :scores, match_ends_at = DATE_ADD(NOW(), INTERVAL :dur MINUTE)";
                $params[':turn'] = $firstTurn;
                $params[':colors'] = json_encode(allocateColors($gameMode));
                $params[':scores'] = json_encode($initialScores);
                $params[':dur'] = $durationMinutes;
            }

            $stmt = $conn->prepare("UPDATE matches SET {$updateFields} WHERE id = :mid AND status = 'waiting'");
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) { 
                $db->rollback(); 
                jsonResponse(false, 'Match was already taken. Please try again.', [], 409); 
            }
            
            $orderId = 'MATCH-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at) VALUES (:uid, :mid, :amount, 'debit', 'match_fee', :desc, :oid, 'success', :bal_before, :bal_after, CURRENT_TIMESTAMP)");
            $stmt->execute([
                ':uid' => $userId, 
                ':mid' => $matchId, 
                ':amount' => $entryFee, 
                ':desc' => "Match entry fee ({$gameMode})", 
                ':oid' => $orderId, 
                ':bal_before' => $user['wallet_balance'], 
                ':bal_after' => $newBalance
            ]);
            
            $db->commit();
            
            jsonResponse(true, $isNowFull ? 'Match found! All players seated.' : "Joined — waiting for {$seatedCount}/{$maxPlayers} players...", [
                'match_id' => $matchId,
                'room_code' => $existingMatch['room_code'],
                'status' => $isNowFull ? 'ready' : 'waiting',
                'game_mode' => $gameMode,
                'players_seated' => $seatedCount,
                'max_players' => $maxPlayers,
                'entry_fee' => $entryFee,
                'prize_pool' => $prizePool,
                'balance_after' => $newBalance,
                'redirect_url' => BASE_URL . '/game.php?match_id=' . $matchId
            ]);
            
        } else {
            // Create new match
            $stmt = $conn->prepare("INSERT INTO matches (room_code, tournament_id, game_mode, entry_fee, prize_pool, platform_fee, player1_id, player1_name, status, current_turn_id, turn_number, turn_started_at, created_at, updated_at) VALUES (:rc, :tid, :mode, :fee, :prize, :pf, :p1id, :p1name, 'waiting', :p1id, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([
                ':rc' => $roomCode, 
                ':tid' => $tournamentId, 
                ':mode' => $gameMode, 
                ':fee' => $entryFee, 
                ':prize' => $prizePool, 
                ':pf' => $platformFee, 
                ':p1id' => $userId, 
                ':p1name' => $user['username']
            ]);
            
            $matchId = $conn->lastInsertId();
            
            $orderId = 'MATCH-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at) VALUES (:uid, :mid, :amount, 'debit', 'match_fee', :desc, :oid, 'success', :bal_before, :bal_after, CURRENT_TIMESTAMP)");
            $stmt->execute([
                ':uid' => $userId, 
                ':mid' => $matchId, 
                ':amount' => $entryFee, 
                ':desc' => "Match entry fee ({$gameMode})", 
                ':oid' => $orderId, 
                ':bal_before' => $user['wallet_balance'], 
                ':bal_after' => $newBalance
            ]);
            
            $db->commit();
            
            jsonResponse(true, "Match created. Waiting for {$maxPlayers} players (1/{$maxPlayers})...", [
                'match_id' => $matchId,
                'room_code' => $roomCode,
                'status' => 'waiting',
                'game_mode' => $gameMode,
                'players_seated' => 1,
                'max_players' => $maxPlayers,
                'entry_fee' => $entryFee,
                'prize_pool' => $prizePool,
                'balance_after' => $newBalance,
                'poll_interval' => 1200
            ]);
        }
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('Match join error: ' . $e->getMessage());
        jsonResponse(false, 'Database error occurred. Please try again.', [], 500);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('Match join error: ' . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// GET ACTIVE MATCH
// ==============================================
function handleGetActiveMatch(int $userId) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, entry_fee, prize_pool, status, player1_name, player2_name, player3_name, player4_name, current_turn_id, dice_value, turn_number, created_at FROM matches WHERE (player1_id = :uid OR player2_id = :uid OR player3_id = :uid OR player4_id = :uid) AND status IN ('waiting', 'ready', 'playing') ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { 
            jsonResponse(true, 'No active match', ['has_active_match' => false]); 
            return; 
        }
        
        jsonResponse(true, 'Active match found', ['has_active_match' => true, 'match' => $match]);
    } catch (PDOException $e) {
        error_log('Match get_active error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET MATCH HISTORY
// ==============================================
function handleGetMatchHistory(int $userId) {
    $limit = max(1, min(200, intval($_GET['limit'] ?? 20)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM matches WHERE (player1_id = :uid OR player2_id = :uid OR player3_id = :uid OR player4_id = :uid) AND status IN ('completed', 'cancelled')");
        $stmt->execute([':uid' => $userId]);
        $total = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("SELECT id, room_code, entry_fee, prize_pool, status, player1_name, player2_name, player3_name, player4_name, winner_id, winner_name, winning_amount, turn_number, created_at, completed_at FROM matches WHERE (player1_id = :uid OR player2_id = :uid OR player3_id = :uid OR player4_id = :uid) AND status IN ('completed', 'cancelled') ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute([':uid' => $userId]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($matches as &$m) {
            $m['entry_fee'] = floatval($m['entry_fee'] ?? 0);
            $m['prize_pool'] = floatval($m['prize_pool'] ?? 0);
            $m['winning_amount'] = floatval($m['winning_amount'] ?? 0);
            $m['winner_id'] = $m['winner_id'] ? intval($m['winner_id']) : null;
        }
        unset($m);
        
        jsonResponse(true, 'Match history retrieved', [
            'matches' => $matches ?: [], 
            'total' => $total, 
            'limit' => $limit, 
            'offset' => $offset
        ]);
    } catch (PDOException $e) {
        error_log('Match get_history error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET ROOM DETAILS
// ==============================================
function handleGetRoomDetails(int $userId) {
    $roomCode = trim($_GET['room_code'] ?? '');
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, entry_fee, prize_pool, status, player1_name, player2_name, player3_name, player4_name, player1_id, player2_id, player3_id, player4_id, current_turn_id, dice_value, turn_number, created_at FROM matches WHERE room_code = :rc");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { jsonResponse(false, 'Room not found', [], 404); }
        
        $match['entry_fee'] = floatval($match['entry_fee'] ?? 0);
        $match['prize_pool'] = floatval($match['prize_pool'] ?? 0);
        
        $isParticipant = (
            $match['player1_id'] == $userId || 
            $match['player2_id'] == $userId || 
            $match['player3_id'] == $userId || 
            $match['player4_id'] == $userId
        );
        
        jsonResponse(true, 'Room details retrieved', ['match' => $match, 'is_participant' => $isParticipant]);
    } catch (PDOException $e) {
        error_log('Match get_room error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// SEARCH MATCH
// ==============================================
function handleSearchMatch(int $userId) {
    $entryFee = floatval($_GET['entry_fee'] ?? 0);
    $gameMode = in_array($_GET['game_mode'] ?? '1vs1', ['1vs1', '1vs4'], true) ? $_GET['game_mode'] : '1vs1';
    
    if ($entryFee <= 0) { jsonResponse(false, 'Invalid entry fee', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, player1_name, entry_fee, prize_pool, created_at FROM matches WHERE entry_fee = :fee AND game_mode = :mode AND status = 'waiting' AND player1_id != :uid ORDER BY created_at ASC LIMIT 5");
        $stmt->execute([':fee' => $entryFee, ':mode' => $gameMode, ':uid' => $userId]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Matches found', [
            'matches' => $matches ?: [], 
            'count' => count($matches ?: [])
        ]);
    } catch (PDOException $e) {
        error_log('Match search error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>