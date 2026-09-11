<?php
/**
 * ======================================================
 * MATCHMAKE.PHP - Tournament Matchmaking (CSRF FIXED)
 * Ludo Tournament Platform - Production Ready
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
if (!$input) {
    jsonResponse(false, 'Invalid JSON payload', [], 400);
}

// 🔥 CSRF Validation with auto-refresh token
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!CSRFToken::validate($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token', [
        'csrf_token' => CSRFToken::generate(),
        'refresh_needed' => true
    ], 403);
}

$required = ['entry_fee', 'tournament_id'];
foreach ($required as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        jsonResponse(false, "Missing required field: {$field}", [], 400);
    }
}

$entryFee = floatval($input['entry_fee']);
if ($entryFee <= 0) {
    jsonResponse(false, 'Invalid entry fee', [], 400);
}

$tournamentId = intval($input['tournament_id']);
if ($tournamentId <= 0) {
    jsonResponse(false, 'Invalid tournament ID', [], 400);
}

if (!isLoggedIn()) {
    jsonResponse(false, 'User not authenticated', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid user session', [], 401);
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
    
    $currentBalance = floatval($user['wallet_balance']);
    if ($currentBalance < $entryFee) {
        $db->rollback();
        jsonResponse(false, 'Insufficient wallet balance', [
            'balance' => $currentBalance, 
            'required' => $entryFee, 
            'shortfall' => round($entryFee - $currentBalance, 2)
        ], 400);
    }
    
    // Check for existing active match (4-player support)
    $stmt = $conn->prepare("SELECT id, room_code, status FROM matches WHERE (player1_id = :uid OR player2_id = :uid OR player3_id = :uid OR player4_id = :uid) AND status IN ('waiting', 'ready', 'playing', 'paused') LIMIT 1 FOR UPDATE");
    $stmt->execute([':uid' => $userId]);
    $existingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingMatch) {
        $db->rollback();
        jsonResponse(false, 'You are already in an active match', [
            'match_id' => $existingMatch['id'], 
            'room_code' => $existingMatch['room_code'], 
            'status' => $existingMatch['status']
        ], 409);
    }
    
    $newBalance = $currentBalance - $entryFee;
    $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
    $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
    
    $orderId = 'LUDO-' . strtoupper(bin2hex(random_bytes(8)));
    $stmt = $conn->prepare("INSERT INTO transactions (user_id, tournament_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at) VALUES (:uid, :tid, :amt, 'debit', 'match_fee', :desc, :oid, 'processing', :bb, :ba, CURRENT_TIMESTAMP)");
    $stmt->execute([
        ':uid' => $userId, 
        ':tid' => $tournamentId, 
        ':amt' => $entryFee, 
        ':desc' => "Match entry fee for tournament #{$tournamentId}", 
        ':oid' => $orderId, 
        ':bb' => $currentBalance, 
        ':ba' => $newBalance
    ]);
    $transactionId = $conn->lastInsertId();
    
    // Look for waiting match
    $stmt = $conn->prepare("SELECT id, room_code, player1_id, player2_id, player3_id, player4_id, status, entry_fee, tournament_id FROM matches WHERE status = 'waiting' AND entry_fee = :fee AND tournament_id = :tid AND player1_id != :uid ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
    $stmt->execute([':fee' => $entryFee, ':tid' => $tournamentId, ':uid' => $userId]);
    $waitingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $roomCode = generateRoomCode();
    $matchId = null;
    $matched = false;
    $data = [];
    
    if ($waitingMatch) {
        $matchId = $waitingMatch['id'];
        
        // Find next empty seat
        $seatField = null;
        for ($i = 1; $i <= 4; $i++) {
            if (empty($waitingMatch["player{$i}_id"])) {
                $seatField = $i;
                break;
            }
        }
        
        if ($seatField === null) {
            $db->rollback();
            jsonResponse(false, 'Match is full', [], 409);
        }
        
        // Collect all seated player IDs including current user
        $seatedIds = [];
        for ($i = 1; $i <= 4; $i++) {
            if ($i == $seatField) {
                $seatedIds[] = $userId;
            } elseif (!empty($waitingMatch["player{$i}_id"])) {
                $seatedIds[] = intval($waitingMatch["player{$i}_id"]);
            }
        }
        
        $totalPlayers = count($seatedIds);
        $maxPlayers = 2; // Default 1vs1
        if (!empty($waitingMatch['player3_id']) || !empty($waitingMatch['player4_id'])) {
            $maxPlayers = 4;
        }
        
        $firstTurn = $seatedIds[array_rand($seatedIds)];
        $isNowFull = ($totalPlayers >= $maxPlayers);
        
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE id IN (" . implode(',', array_fill(0, count($seatedIds), '?')) . ")");
        $stmt->execute($seatedIds);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $playerNames = [];
        foreach ($players as $p) { $playerNames[$p['id']] = $p['username']; }
        
        $updateFields = "player{$seatField}_id = :pid, player{$seatField}_name = :pname, updated_at = CURRENT_TIMESTAMP";
        $params = [':pid' => $userId, ':pname' => $user['username'], ':mid' => $matchId];
        
        if ($isNowFull) {
            $updateFields .= ", status = 'ready', current_turn_id = :turn, turn_started_at = CURRENT_TIMESTAMP, started_at = CURRENT_TIMESTAMP";
            $params[':turn'] = $firstTurn;
        }
        
        $stmt = $conn->prepare("UPDATE matches SET {$updateFields} WHERE id = :mid AND status = 'waiting'");
        $stmt->execute($params);
        
        if ($stmt->rowCount() === 0) { 
            $db->rollback(); 
            jsonResponse(false, 'Match was already taken. Please try again.', [], 409); 
        }
        
        $matched = true;
        $data = [
            'match_id' => $matchId,
            'room_code' => $waitingMatch['room_code'],
            'status' => $isNowFull ? 'ready' : 'waiting',
            'current_turn' => $firstTurn === $userId ? $seatField : 1,
            'player1_id' => intval($waitingMatch['player1_id']),
            'player1_name' => $waitingMatch['player1_name'] ?? 'Player 1',
            'player2_id' => $seatField == 2 ? $userId : intval($waitingMatch['player2_id'] ?? 0),
            'player2_name' => $seatField == 2 ? $user['username'] : ($waitingMatch['player2_name'] ?? null),
            'entry_fee' => $entryFee,
            'tournament_id' => $tournamentId,
            'is_creator' => false,
            'message' => $isNowFull ? 'Successfully joined match!' : "Joined — waiting for {$totalPlayers}/{$maxPlayers} players..."
        ];
    } else {
        $platformFee = calculatePlatformFee($entryFee);
        $prizePool = calculatePrizePool($entryFee, 2);
        
        $stmt = $conn->prepare("INSERT INTO matches (tournament_id, room_code, entry_fee, prize_pool, platform_fee, player1_id, player1_name, status, current_turn_id, turn_number, turn_started_at, created_at, updated_at) VALUES (:tid, :rc, :fee, :prize, :pf, :p1id, :p1name, 'waiting', :p1id, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':tid' => $tournamentId, 
            ':rc' => $roomCode, 
            ':fee' => $entryFee, 
            ':prize' => $prizePool, 
            ':pf' => $platformFee, 
            ':p1id' => $userId, 
            ':p1name' => $user['username']
        ]);
        
        $matchId = $conn->lastInsertId();
        
        $data = [
            'match_id' => $matchId,
            'room_code' => $roomCode,
            'status' => 'waiting',
            'current_turn' => 1,
            'player1_id' => $userId,
            'player1_name' => $user['username'],
            'player2_id' => null,
            'player2_name' => null,
            'entry_fee' => $entryFee,
            'tournament_id' => $tournamentId,
            'is_creator' => true,
            'message' => 'Match created. Waiting for opponent...',
            'poll_interval' => 1200
        ];
    }
    
    if ($matched) {
        $stmt = $conn->prepare("UPDATE tournaments SET current_players = current_players + 1 WHERE id = :tid");
        $stmt->execute([':tid' => $tournamentId]);
    }
    
    $db->commit();
    
    // Log matchmaking
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'), 
        'event' => $matched ? 'match_joined' : 'match_created', 
        'user_id' => $userId, 
        'match_id' => $matchId, 
        'entry_fee' => $entryFee, 
        'room_code' => $data['room_code'], 
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $logFile = dirname(__DIR__) . '/logs/matchmaking.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) { mkdir($logDir, 0755, true); }
    file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    jsonResponse(true, $data['message'], $data);
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) { $db->rollback(); }
    error_log('Matchmake error: ' . $e->getMessage());
    jsonResponse(false, 'Database error occurred. Please try again.', [], 500);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) { $db->rollback(); }
    jsonResponse(false, $e->getMessage(), [], 500);
}
?>