<?php
/**
 * ======================================================
 * INVITE.PHP - Friend Invite API (CSRF FIXED)
 * Ludo Tournament Platform - Complete Invite System
 * Version: 3.2.0 - CSRF AUTO-REFRESH SUPPORT
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
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid session', [], 401);
}

// 🔥 CSRF Validation Helper for POST requests
function validateCsrfOrFail() {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $providedToken = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    
    if (!$providedToken || !CSRFToken::validate($providedToken)) {
        jsonResponse(false, 'Invalid CSRF token', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403);
    }
    
    return $input;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create': 
        $input = validateCsrfOrFail();
        handleCreateInvite($userId, $input); 
        break;
    case 'check_room': 
        handleCheckRoom(); 
        break;
    case 'join': 
        $input = validateCsrfOrFail();
        handleJoinRoom($userId, $input); 
        break;
    case 'get_room': 
        handleGetRoom(); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// CREATE INVITE
// ==============================================
function handleCreateInvite(int $userId, array $input) {
    $roomCode = trim($input['room_code'] ?? '');
    
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, status, player1_id, player2_id, player3_id, player4_id, entry_fee, prize_pool FROM matches WHERE room_code = :rc");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { jsonResponse(false, 'Room not found', [], 404); }
        
        $inviteCode = 'INV-' . strtoupper(uniqid() . bin2hex(random_bytes(3)));
        
        $playerCount = 0;
        for ($i = 1; $i <= 4; $i++) {
            if (!empty($match["player{$i}_id"])) $playerCount++;
        }
        
        jsonResponse(true, 'Invite created', [
            'room_code' => $roomCode, 
            'match_id' => $match['id'],
            'invite_code' => $inviteCode,
            'invite_url' => BASE_URL . '/join.php?room=' . $roomCode,
            'status' => $match['status'],
            'player_count' => $playerCount
        ]);
    } catch (PDOException $e) {
        error_log('[invite create] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// CHECK ROOM
// ==============================================
function handleCheckRoom() {
    $roomCode = trim($_GET['room'] ?? '');
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, status, game_mode, player1_id, player2_id, player3_id, player4_id, player1_name, player2_name, player3_name, player4_name, entry_fee, prize_pool, created_at FROM matches WHERE room_code = :rc");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { jsonResponse(false, 'Room not found', [], 404); }
        
        $playerCount = 0;
        $maxPlayers = ($match['game_mode'] === '1vs4') ? 4 : 2;
        
        for ($i = 1; $i <= $maxPlayers; $i++) {
            if (!empty($match["player{$i}_id"])) $playerCount++;
        }
        
        $isFull = ($playerCount >= $maxPlayers);
        
        jsonResponse(true, 'Room found', [
            'room' => [
                'id' => $match['id'], 
                'room_code' => $match['room_code'],
                'status' => $match['status'], 
                'player1_name' => $match['player1_name'],
                'player2_name' => $match['player2_name'],
                'player3_name' => $match['player3_name'] ?? null,
                'player4_name' => $match['player4_name'] ?? null,
                'entry_fee' => floatval($match['entry_fee']),
                'prize_pool' => floatval($match['prize_pool']),
                'player_count' => $playerCount, 
                'max_players' => $maxPlayers,
                'is_full' => $isFull,
                'created_at' => $match['created_at']
            ]
        ]);
    } catch (PDOException $e) {
        error_log('[invite check] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// JOIN ROOM
// ==============================================
function handleJoinRoom(int $userId, array $input) {
    $roomCode = trim($input['room_code'] ?? '');
    
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT id, room_code, status, game_mode, player1_id, player2_id, player3_id, player4_id, entry_fee, prize_pool FROM matches WHERE room_code = :rc FOR UPDATE");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { $db->rollback(); jsonResponse(false, 'Room not found', [], 404); }
        if (in_array($match['status'], ['playing', 'completed'])) { $db->rollback(); jsonResponse(false, 'Game already started or completed', [], 400); }
        
        // Check if already in room
        $existingPlayers = array_filter([
            intval($match['player1_id'] ?? 0),
            intval($match['player2_id'] ?? 0),
            intval($match['player3_id'] ?? 0),
            intval($match['player4_id'] ?? 0)
        ]);
        
        if (in_array($userId, $existingPlayers)) { 
            $db->rollback(); 
            jsonResponse(false, 'You are already in this room', [], 409); 
        }
        
        // Find next empty player slot
        $nextSlot = null;
        for ($i = 1; $i <= 4; $i++) {
            if (empty($match["player{$i}_id"])) {
                $nextSlot = $i;
                break;
            }
        }
        
        if ($nextSlot === null) { 
            $db->rollback(); 
            jsonResponse(false, 'Room is full', [], 409); 
        }
        
        $stmt = $conn->prepare("SELECT id, username, wallet_balance FROM users WHERE id = :uid");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) { $db->rollback(); jsonResponse(false, 'User not found', [], 404); }
        
        $entryFee = floatval($match['entry_fee']);
        if ($user['wallet_balance'] < $entryFee) { 
            $db->rollback(); 
            jsonResponse(false, 'Insufficient balance', [], 400); 
        }
        
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
        
        // 🔥 FIX: determine max players from the match's actual game_mode, not
        // from whether player3/player4 are already filled — that guess is
        // always wrong for a 1v4 room until the 3rd player has already
        // joined, so a 4-player room would wrongly go 'ready' after only 2.
        $totalPlayers = count($existingPlayers) + 1;
        $maxPlayers = ($match['game_mode'] === '1vs4') ? 4 : 2;
        $isNowFull = ($totalPlayers >= $maxPlayers);
        $newStatus = $isNowFull ? 'ready' : $match['status'];
        
        // Set first turn randomly if this is the first player
        $firstTurn = $existingPlayers[0] ?? $userId;
        if (count($existingPlayers) === 0) {
            $firstTurn = $userId;
        }
        
        // Update match with new player
        $playerNameField = "player{$nextSlot}_name";
        $playerIdField = "player{$nextSlot}_id";
        
        if ($isNowFull) {
            $initialScores = [];
            for ($i = 1; $i <= $maxPlayers; $i++) { $initialScores["player{$i}"] = 0; }
            $durationMinutes = getDefaultMatchDurationMinutes($match['game_mode'] ?? '1vs1');

            $stmt = $conn->prepare("UPDATE matches SET {$playerIdField} = :pid, {$playerNameField} = :pname, status = :status, current_turn_id = :turn, turn_started_at = CURRENT_TIMESTAMP, player_colors = :colors, scores = :scores, match_ends_at = DATE_ADD(NOW(), INTERVAL :dur MINUTE), updated_at = CURRENT_TIMESTAMP WHERE id = :mid");
            $stmt->execute([
                ':pid' => $userId, 
                ':pname' => $user['username'], 
                ':status' => $newStatus, 
                ':turn' => $firstTurn, 
                ':colors' => json_encode(allocateColors($match['game_mode'] ?? '1vs1')),
                ':scores' => json_encode($initialScores),
                ':dur' => $durationMinutes,
                ':mid' => $match['id']
            ]);
        } else {
            $stmt = $conn->prepare("UPDATE matches SET {$playerIdField} = :pid, {$playerNameField} = :pname, status = :status, current_turn_id = :turn, turn_started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :mid");
            $stmt->execute([
                ':pid' => $userId, 
                ':pname' => $user['username'], 
                ':status' => $newStatus, 
                ':turn' => $firstTurn, 
                ':mid' => $match['id']
            ]);
        }
        
        $orderId = 'JOIN-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at) VALUES (:uid, :mid, :amt, 'debit', 'match_fee', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':uid' => $userId, 
            ':mid' => $match['id'], 
            ':amt' => $entryFee, 
            ':desc' => "Joined via invite", 
            ':oid' => $orderId, 
            ':bb' => $user['wallet_balance'], 
            ':ba' => $newBalance
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Successfully joined room!', [
            'match_id' => $match['id'], 
            'room_code' => $match['room_code'],
            'player1_name' => $match['player1_name'], 
            'player2_name' => $user['username'],
            'entry_fee' => $entryFee, 
            'prize_pool' => floatval($match['prize_pool']),
            'redirect_url' => BASE_URL . '/game.php?match_id=' . $match['id']
        ]);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[invite join] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET ROOM
// ==============================================
function handleGetRoom() {
    $roomCode = trim($_GET['room_code'] ?? '');
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, entry_fee, prize_pool, status, player1_id, player2_id, player3_id, player4_id, player1_name, player2_name, player3_name, player4_name, created_at FROM matches WHERE room_code = :rc");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { jsonResponse(false, 'Room not found', [], 404); }
        
        jsonResponse(true, 'Room details', ['room' => $match]);
    } catch (PDOException $e) {
        error_log('[invite get_room] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>