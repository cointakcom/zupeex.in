<?php
/**
 * ======================================================
 * INVITE.PHP - Complete Matchmaking & Join System
 * Zupeex - Real Money Ludo Platform
 * Version: 5.0.0 - COMPLETE REWRITE
 * ======================================================
 * FEATURES:
 *  1. Quick Match (Auto-pair users)
 *  2. Friend Invite (Create room + share link)
 *  3. Tournament Join
 *  4. Custom Room Create (1vs1, 1vs4)
 *  5. CSRF Protection + Transaction Safety
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

// ======================================================
// CSRF VALIDATION
// ======================================================
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

// ======================================================
// ROUTING
// ======================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create_room':
        $input = validateCsrfOrFail();
        handleCreateRoom($userId, $input);
        break;
    case 'check_room':
        handleCheckRoom();
        break;
    case 'join':
        $input = validateCsrfOrFail();
        handleJoinRoom($userId, $input);
        break;
    case 'quick_match':
        $input = validateCsrfOrFail();
        handleQuickMatch($userId, $input);
        break;
    case 'tournament_join':
        $input = validateCsrfOrFail();
        handleTournamentJoin($userId, $input);
        break;
    case 'leave_waiting':
        $input = validateCsrfOrFail();
        handleLeaveWaiting($userId, $input);
        break;
    default:
        jsonResponse(false, 'Invalid action', [], 400);
}

// ======================================================
// CREATE ROOM (Friend Invite)
// ======================================================
function handleCreateRoom(int $userId, array $input): void {
    $gameMode = $input['game_mode'] ?? '1vs1';
    $entryFee = floatval($input['entry_fee'] ?? 10);
    
    if (!in_array($gameMode, ['1vs1', '1vs4'], true)) {
        jsonResponse(false, 'Invalid game mode', [], 400);
    }
    
    if ($entryFee <= 0) {
        jsonResponse(false, 'Invalid entry fee', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();
        
        // Check user balance
        $stmt = $conn->prepare("SELECT id, username, wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }
        
        if ($user['wallet_balance'] < $entryFee) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [], 400);
        }
        
        // Generate unique room code
        $roomCode = strtoupper(substr(md5(uniqid() . $userId . time()), 0, 8));
        
        // Deduct entry fee
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
        
        // Create room (user as player1)
        $maxPlayers = ($gameMode === '1vs4') ? 4 : 2;
        $prizePool = $entryFee * $maxPlayers * 0.85; // 85% payout, 15% platform fee
        
        $stmt = $conn->prepare("
            INSERT INTO matches 
            (room_code, game_mode, entry_fee, prize_pool, player1_id, player1_name, 
             status, current_turn_id, board_state, player_colors, created_at, updated_at)
            VALUES 
            (:rc, :gm, :ef, :pp, :p1, :pname, 'waiting', :p1, '{}', '{}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':rc' => $roomCode,
            ':gm' => $gameMode,
            ':ef' => $entryFee,
            ':pp' => $prizePool,
            ':p1' => $userId,
            ':pname' => $user['username']
        ]);
        
        $matchId = intval($conn->lastInsertId());
        
        // Log transaction
        $orderId = 'ROOM-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO transactions 
            (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
            VALUES (:uid, :mid, :amt, 'debit', 'room_entry', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':mid' => $matchId,
            ':amt' => $entryFee,
            ':desc' => "Created room {$roomCode}",
            ':oid' => $orderId,
            ':bb' => $user['wallet_balance'],
            ':ba' => $newBalance
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Room created successfully', [
            'match_id' => $matchId,
            'room_code' => $roomCode,
            'game_mode' => $gameMode,
            'entry_fee' => $entryFee,
            'prize_pool' => $prizePool,
            'invite_link' => BASE_URL . '/join.php?room=' . $roomCode,
            'qr_code' => BASE_URL . '/api/qr.php?room=' . $roomCode,
            'max_players' => $maxPlayers
        ]);
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[invite create_room] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ======================================================
// CHECK ROOM STATUS
// ======================================================
function handleCheckRoom(): void {
    $roomCode = trim($_GET['room'] ?? '');
    if (empty($roomCode)) {
        jsonResponse(false, 'Room code required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, room_code, status, game_mode, entry_fee, prize_pool,
                   player1_id, player2_id, player3_id, player4_id,
                   player1_name, player2_name, player3_name, player4_name,
                   created_at
            FROM matches
            WHERE room_code = :rc
        ");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Room not found', [], 404);
        }
        
        $maxPlayers = ($match['game_mode'] === '1vs4') ? 4 : 2;
        $players = [];
        $playerCount = 0;
        
        for ($i = 1; $i <= $maxPlayers; $i++) {
            if (!empty($match["player{$i}_id"])) {
                $players[] = [
                    'slot' => $i,
                    'name' => $match["player{$i}_name"],
                    'id' => intval($match["player{$i}_id"])
                ];
                $playerCount++;
            }
        }
        
        $isFull = ($playerCount >= $maxPlayers);
        
        jsonResponse(true, 'Room found', [
            'room' => [
                'id' => intval($match['id']),
                'room_code' => $match['room_code'],
                'status' => $match['status'],
                'game_mode' => $match['game_mode'],
                'entry_fee' => floatval($match['entry_fee']),
                'prize_pool' => floatval($match['prize_pool']),
                'players' => $players,
                'player_count' => $playerCount,
                'max_players' => $maxPlayers,
                'is_full' => $isFull,
                'created_at' => $match['created_at']
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log('[invite check_room] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ======================================================
// JOIN ROOM (Via Invite Link)
// ======================================================
function handleJoinRoom(int $userId, array $input): void {
    $roomCode = trim($input['room_code'] ?? '');
    
    if (empty($roomCode)) {
        jsonResponse(false, 'Room code required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();
        
        // Lock match for update
        $stmt = $conn->prepare("
            SELECT id, room_code, status, game_mode, entry_fee, prize_pool,
                   player1_id, player2_id, player3_id, player4_id,
                   player1_name, player2_name, player3_name, player4_name,
                   current_turn_id
            FROM matches
            WHERE room_code = :rc
            FOR UPDATE
        ");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Room not found', [], 404);
        }
        
        if ($match['status'] !== 'waiting') {
            $db->rollback();
            jsonResponse(false, 'Game already started or ended', [], 400);
        }
        
        // Check if user already in room
        $maxPlayers = ($match['game_mode'] === '1vs4') ? 4 : 2;
        for ($i = 1; $i <= $maxPlayers; $i++) {
            if (intval($match["player{$i}_id"] ?? 0) === $userId) {
                $db->rollback();
                jsonResponse(false, 'You are already in this room', [], 409);
            }
        }
        
        // Get user info
        $stmt = $conn->prepare("SELECT id, username, wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }
        
        $entryFee = floatval($match['entry_fee']);
        if ($user['wallet_balance'] < $entryFee) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance. ₹' . number_format($entryFee, 2) . ' required.', [], 400);
        }
        
        // Deduct entry fee
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
        
        // Find next empty slot
        $nextSlot = null;
        for ($i = 1; $i <= $maxPlayers; $i++) {
            if (empty($match["player{$i}_id"])) {
                $nextSlot = $i;
                break;
            }
        }
        
        if ($nextSlot === null) {
            $db->rollback();
            jsonResponse(false, 'Room is full', [], 409);
        }
        
        // Update match with new player
        $playerIdField = "player{$nextSlot}_id";
        $playerNameField = "player{$nextSlot}_name";
        
        // Count current players
        $playerCount = 0;
        for ($i = 1; $i <= $maxPlayers; $i++) {
            if (!empty($match["player{$i}_id"])) $playerCount++;
        }
        $playerCount++; // Add this new player
        
        // If room is now full, transition to "ready"
        $newStatus = ($playerCount >= $maxPlayers) ? 'ready' : 'waiting';
        
        // Initialize game if full
        $colors = allocateColors($match['game_mode']);
        $boardState = initializeBoardState($maxPlayers);
        
        $stmt = $conn->prepare("
            UPDATE matches
            SET {$playerIdField} = :pid,
                {$playerNameField} = :pname,
                status = :status,
                current_turn_id = COALESCE(current_turn_id, :pid),
                turn_started_at = CURRENT_TIMESTAMP,
                board_state = :bs,
                player_colors = :colors,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :mid
        ");
        
        $stmt->execute([
            ':pid' => $userId,
            ':pname' => $user['username'],
            ':status' => $newStatus,
            ':bs' => json_encode($boardState),
            ':colors' => json_encode($colors),
            ':mid' => $match['id']
        ]);
        
        // Log transaction
        $orderId = 'JOIN-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO transactions 
            (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
            VALUES (:uid, :mid, :amt, 'debit', 'room_join', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':mid' => $match['id'],
            ':amt' => $entryFee,
            ':desc' => "Joined room {$roomCode}",
            ':oid' => $orderId,
            ':bb' => $user['wallet_balance'],
            ':ba' => $newBalance
        ]);
        
        $db->commit();
        
        jsonResponse(true, $newStatus === 'ready' ? 'Room full! Starting game...' : 'Joined room successfully', [
            'match_id' => intval($match['id']),
            'room_code' => $match['room_code'],
            'game_mode' => $match['game_mode'],
            'status' => $newStatus,
            'player_count' => $playerCount,
            'max_players' => $maxPlayers,
            'redirect_url' => $newStatus === 'ready' ? (BASE_URL . '/game.php?match_id=' . $match['id']) : null
        ]);
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[invite join] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ======================================================
// QUICK MATCH (Auto-pair users)
// ======================================================
function handleQuickMatch(int $userId, array $input): void {
    $gameMode = $input['game_mode'] ?? '1vs1';
    $entryFee = floatval($input['entry_fee'] ?? 10);
    
    if (!in_array($gameMode, ['1vs1', '1vs4'], true)) {
        jsonResponse(false, 'Invalid game mode', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();
        
        // Check user balance
        $stmt = $conn->prepare("SELECT id, username, wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }
        
        if ($user['wallet_balance'] < $entryFee) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [], 400);
        }
        
        // Look for existing waiting room
        $maxPlayers = ($gameMode === '1vs4') ? 4 : 2;
        $stmt = $conn->prepare("
            SELECT id, room_code, player1_id, player2_id, player3_id, player4_id
            FROM matches
            WHERE game_mode = :gm AND status = 'waiting' AND entry_fee = :ef
            AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([':gm' => $gameMode, ':ef' => $entryFee]);
        $existingRoom = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Deduct entry fee
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
        
        if ($existingRoom) {
            // Join existing room
            $nextSlot = null;
            for ($i = 1; $i <= $maxPlayers; $i++) {
                if (empty($existingRoom["player{$i}_id"])) {
                    $nextSlot = $i;
                    break;
                }
            }
            
            if ($nextSlot === null) {
                $db->rollback();
                jsonResponse(false, 'Room became full', [], 409);
            }
            
            $playerIdField = "player{$nextSlot}_id";
            $playerNameField = "player{$nextSlot}_name";
            
            $playerCount = 0;
            for ($i = 1; $i <= $maxPlayers; $i++) {
                if (!empty($existingRoom["player{$i}_id"])) $playerCount++;
            }
            $playerCount++;
            
            $newStatus = ($playerCount >= $maxPlayers) ? 'ready' : 'waiting';
            
            $colors = allocateColors($gameMode);
            $boardState = initializeBoardState($maxPlayers);
            
            $stmt = $conn->prepare("
                UPDATE matches
                SET {$playerIdField} = :pid,
                    {$playerNameField} = :pname,
                    status = :status,
                    current_turn_id = COALESCE(current_turn_id, :pid),
                    turn_started_at = CURRENT_TIMESTAMP,
                    board_state = :bs,
                    player_colors = :colors,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :mid
            ");
            
            $stmt->execute([
                ':pid' => $userId,
                ':pname' => $user['username'],
                ':status' => $newStatus,
                ':bs' => json_encode($boardState),
                ':colors' => json_encode($colors),
                ':mid' => $existingRoom['id']
            ]);
            
            // Log transaction
            $orderId = 'QM-' . strtoupper(uniqid());
            $stmt = $conn->prepare("
                INSERT INTO transactions 
                (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
                VALUES (:uid, :mid, :amt, 'debit', 'quick_match', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':mid' => $existingRoom['id'],
                ':amt' => $entryFee,
                ':desc' => "Quick match joined",
                ':oid' => $orderId,
                ':bb' => $user['wallet_balance'],
                ':ba' => $newBalance
            ]);
            
            $db->commit();
            
            jsonResponse(true, $newStatus === 'ready' ? 'Match found! Starting...' : 'Waiting for opponent...', [
                'match_id' => intval($existingRoom['id']),
                'room_code' => $existingRoom['room_code'],
                'status' => $newStatus,
                'player_count' => $playerCount,
                'max_players' => $maxPlayers,
                'waiting' => ($newStatus === 'waiting'),
                'redirect_url' => $newStatus === 'ready' ? (BASE_URL . '/game.php?match_id=' . $existingRoom['id']) : null
            ]);
            
        } else {
            // Create new waiting room
            $roomCode = strtoupper(substr(md5(uniqid() . $userId . time()), 0, 8));
            $prizePool = $entryFee * $maxPlayers * 0.85;
            
            $stmt = $conn->prepare("
                INSERT INTO matches 
                (room_code, game_mode, entry_fee, prize_pool, player1_id, player1_name, 
                 status, current_turn_id, board_state, player_colors, created_at, updated_at)
                VALUES 
                (:rc, :gm, :ef, :pp, :p1, :pname, 'waiting', :p1, '{}', '{}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':rc' => $roomCode,
                ':gm' => $gameMode,
                ':ef' => $entryFee,
                ':pp' => $prizePool,
                ':p1' => $userId,
                ':pname' => $user['username']
            ]);
            
            $matchId = intval($conn->lastInsertId());
            
            // Log transaction
            $orderId = 'QM-' . strtoupper(uniqid());
            $stmt = $conn->prepare("
                INSERT INTO transactions 
                (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
                VALUES (:uid, :mid, :amt, 'debit', 'quick_match', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':mid' => $matchId,
                ':amt' => $entryFee,
                ':desc' => "Quick match created",
                ':oid' => $orderId,
                ':bb' => $user['wallet_balance'],
                ':ba' => $newBalance
            ]);
            
            $db->commit();
            
            jsonResponse(true, 'Waiting for opponent...', [
                'match_id' => $matchId,
                'room_code' => $roomCode,
                'status' => 'waiting',
                'player_count' => 1,
                'max_players' => $maxPlayers,
                'waiting' => true,
                'timeout' => 120 // 2 minute timeout
            ]);
        }
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[invite quick_match] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ======================================================
// TOURNAMENT JOIN
// ======================================================
function handleTournamentJoin(int $userId, array $input): void {
    $tournamentId = intval($input['tournament_id'] ?? 0);
    
    if ($tournamentId <= 0) {
        jsonResponse(false, 'Tournament ID required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();
        
        // Get tournament
        $stmt = $conn->prepare("
            SELECT id, entry_fee, registration_open, registered_players, total_players
            FROM tournaments
            WHERE id = :tid
            FOR UPDATE
        ");
        $stmt->execute([':tid' => $tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) {
            $db->rollback();
            jsonResponse(false, 'Tournament not found', [], 404);
        }
        
        if (!$tournament['registration_open']) {
            $db->rollback();
            jsonResponse(false, 'Registration closed', [], 400);
        }
        
        if ($tournament['registered_players'] >= $tournament['total_players']) {
            $db->rollback();
            jsonResponse(false, 'Tournament full', [], 409);
        }
        
        // Check if already registered
        $stmt = $conn->prepare("
            SELECT id FROM tournament_registrations
            WHERE tournament_id = :tid AND user_id = :uid
        ");
        $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
        if ($stmt->fetch()) {
            $db->rollback();
            jsonResponse(false, 'Already registered', [], 409);
        }
        
        // Check user balance
        $stmt = $conn->prepare("SELECT id, username, wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }
        
        $entryFee = floatval($tournament['entry_fee']);
        if ($user['wallet_balance'] < $entryFee) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [], 400);
        }
        
        // Deduct entry fee
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
        
        // Register for tournament
        $stmt = $conn->prepare("
            INSERT INTO tournament_registrations 
            (tournament_id, user_id, entry_fee_paid, status, registered_at)
            VALUES (:tid, :uid, :fee, 'registered', CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':tid' => $tournamentId,
            ':uid' => $userId,
            ':fee' => $entryFee
        ]);
        
        // Update tournament player count
        $stmt = $conn->prepare("
            UPDATE tournaments
            SET registered_players = registered_players + 1, updated_at = CURRENT_TIMESTAMP
            WHERE id = :tid
        ");
        $stmt->execute([':tid' => $tournamentId]);
        
        // Log transaction
        $orderId = 'TOURN-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO transactions 
            (user_id, tournament_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
            VALUES (:uid, :tid, :amt, 'debit', 'tournament_entry', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':tid' => $tournamentId,
            ':amt' => $entryFee,
            ':desc' => "Tournament registration",
            ':oid' => $orderId,
            ':bb' => $user['wallet_balance'],
            ':ba' => $newBalance
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Registered successfully', [
            'tournament_id' => $tournamentId,
            'entry_fee' => $entryFee,
            'status' => 'registered'
        ]);
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[invite tournament_join] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ======================================================
// LEAVE WAITING ROOM
// ======================================================
function handleLeaveWaiting(int $userId, array $input): void {
    $matchId = intval($input['match_id'] ?? 0);
    
    if ($matchId <= 0) {
        jsonResponse(false, 'Match ID required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();
        
        $stmt = $conn->prepare("
            SELECT id, player1_id, player2_id, player3_id, player4_id, entry_fee, status
            FROM matches
            WHERE id = :mid
            FOR UPDATE
        ");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }
        
        if ($match['status'] !== 'waiting') {
            $db->rollback();
            jsonResponse(false, 'Cannot leave a started game', [], 400);
        }
        
        // Find which slot user is in
        $playerSlot = null;
        for ($i = 1; $i <= 4; $i++) {
            if (intval($match["player{$i}_id"] ?? 0) === $userId) {
                $playerSlot = $i;
                break;
            }
        }
        
        if ($playerSlot === null) {
            $db->rollback();
            jsonResponse(false, 'User not in this match', [], 404);
        }
        
        // Refund entry fee
        $entryFee = floatval($match['entry_fee']);
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
        
        // Remove player from room
        $playerIdField = "player{$playerSlot}_id";
        $playerNameField = "player{$playerSlot}_name";
        
        $stmt = $conn->prepare("
            UPDATE matches
            SET {$playerIdField} = NULL, {$playerNameField} = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = :mid
        ");
        $stmt->execute([':mid' => $matchId]);
        
        // Log transaction
        $orderId = 'REFUND-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO transactions 
            (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
            VALUES (:uid, :mid, :amt, 'credit', 'refund', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':mid' => $matchId,
            ':amt' => $entryFee,
            ':desc' => "Left waiting room",
            ':oid' => $orderId,
            ':bb' => 0, // simplified
            ':ba' => $entryFee
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Left room successfully', [
            'refunded' => $entryFee
        ]);
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[invite leave_waiting] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ======================================================
// HELPERS
// ======================================================
function initializeBoardState(int $maxPlayers): array {
    $boardState = [];
    for ($i = 1; $i <= $maxPlayers; $i++) {
        $boardState["player{$i}"] = [
            'token1' => -1,
            'token2' => -1,
            'token3' => -1,
            'token4' => -1
        ];
    }
    return $boardState;
}

?>
