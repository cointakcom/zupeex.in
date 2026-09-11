<?php
/**
 * ======================================================
 * TICKETS.PHP - Tournament Tickets System (CSRF FIXED)
 * Version: 2.1.0 - COMPLETE WITH AUTO-REFRESH
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once __DIR__ . '/auth.php';

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

// 🔥 CSRF ONLY FOR POST REQUESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfMiddleware();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list': 
        handleList(); 
        break;
    case 'join': 
        handleJoin(); 
        break;
    case 'status': 
        handleStatus(); 
        break;
    case 'cancel': 
        handleCancel(); 
        break;
    case 'admin_list': 
        handleAdminList(); 
        break;
    case 'admin_create': 
        handleAdminCreate(); 
        break;
    case 'admin_toggle': 
        handleAdminToggle(); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// LIST TICKETS (PUBLIC - GET)
// ==============================================
function handleList() {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT t.id, t.game_mode, t.entry_fee, t.duration_minutes, t.payout_percent, 
                   (SELECT COUNT(*) FROM ticket_queue q WHERE q.ticket_id = t.id AND q.status = 'waiting') AS waiting_count 
            FROM game_tickets t 
            WHERE t.is_active = 1 
            ORDER BY t.game_mode ASC, t.entry_fee ASC
        ");
        $stmt->execute();
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = ['1vs1' => [], '1vs4' => []];
        foreach ($tickets as $t) {
            $requiredPlayers = $t['game_mode'] === '1vs1' ? 2 : 4;
            $totalPool = floatval($t['entry_fee']) * $requiredPlayers;
            $winnerPayout = round($totalPool * (floatval($t['payout_percent']) / 100), 2);
            
            $mode = $t['game_mode'] === '1vs1' ? '1vs1' : '1vs4';
            $grouped[$mode][] = [
                'id' => intval($t['id']),
                'game_mode' => $t['game_mode'],
                'entry_fee' => floatval($t['entry_fee']),
                'duration_minutes' => intval($t['duration_minutes']),
                'players_needed' => $requiredPlayers,
                'waiting_count' => intval($t['waiting_count']),
                'winner_payout' => $winnerPayout
            ];
        }

        jsonResponse(true, 'Tickets retrieved', ['tickets' => $grouped]);
    } catch (PDOException $e) {
        error_log('[tickets list] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// JOIN TICKET (POST - CSRF REQUIRED)
// ==============================================
function handleJoin() {
    if (!isLoggedIn()) jsonResponse(false, 'Please login first', [], 401);
    $userId = getCurrentUserId();
    if (!$userId) jsonResponse(false, 'Invalid session', [], 401);

    $input = json_decode(file_get_contents('php://input'), true);
    $ticketId = intval($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) jsonResponse(false, 'Invalid ticket', [], 400);

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        // Check if already in queue
        $stmt = $conn->prepare("SELECT id FROM ticket_queue WHERE user_id = :uid AND status = 'waiting' FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetch()) { 
            $db->rollback(); 
            jsonResponse(false, 'You are already waiting in a ticket queue', [], 409); 
        }

        // Get ticket
        $stmt = $conn->prepare("SELECT * FROM game_tickets WHERE id = :id AND is_active = 1 FOR UPDATE");
        $stmt->execute([':id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) { 
            $db->rollback(); 
            jsonResponse(false, 'Ticket not found or inactive', [], 404); 
        }

        $entryFee = floatval($ticket['entry_fee']);
        $requiredPlayers = $ticket['game_mode'] === '1vs1' ? 2 : 4;

        // Check balance
        $stmt = $conn->prepare("SELECT id, username, wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || floatval($user['wallet_balance']) < $entryFee) {
            $db->rollback();
            jsonResponse(false, 'Insufficient wallet balance', [
                'required' => $entryFee, 
                'balance' => floatval($user['wallet_balance'] ?? 0)
            ], 400);
        }

        // Deduct entry fee
        $newBalance = floatval($user['wallet_balance']) - $entryFee;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);

        // Record transaction
        $orderId = 'TKT-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at) VALUES (:uid, :amt, 'debit', 'match_fee', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':uid' => $userId,
            ':amt' => $entryFee,
            ':desc' => "Tournament Ticket entry ({$ticket['game_mode']}, ₹{$entryFee})",
            ':oid' => $orderId,
            ':bb' => $user['wallet_balance'],
            ':ba' => $newBalance
        ]);
        $transactionId = $conn->lastInsertId();

        // Add to queue
        $stmt = $conn->prepare("INSERT INTO ticket_queue (ticket_id, user_id, status, transaction_id, joined_at) VALUES (:tid, :uid, 'waiting', :txid, CURRENT_TIMESTAMP)");
        $stmt->execute([':tid' => $ticketId, ':uid' => $userId, ':txid' => $transactionId]);

        // Get waiting players
        $stmt = $conn->prepare("SELECT q.id, q.user_id, u.username FROM ticket_queue q JOIN users u ON q.user_id = u.id WHERE q.ticket_id = :tid AND q.status = 'waiting' ORDER BY q.joined_at ASC FOR UPDATE");
        $stmt->execute([':tid' => $ticketId]);
        $waitingPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If not enough players, wait
        if (count($waitingPlayers) < $requiredPlayers) {
            $db->commit();
            $newCsrf = refreshCsrfToken();
            jsonResponse(true, 'Waiting for players (' . count($waitingPlayers) . '/' . $requiredPlayers . ')', [
                'status' => 'waiting',
                'ticket_id' => $ticketId,
                'waiting_count' => count($waitingPlayers),
                'players_needed' => $requiredPlayers,
                'csrf_token' => $newCsrf
            ]);
            return;
        }

        // Create match
        $matchPlayers = array_slice($waitingPlayers, 0, $requiredPlayers);
        $roomCode = generateRoomCode();
        $totalPool = $entryFee * $requiredPlayers;
        $payoutPercent = floatval($ticket['payout_percent']);
        $prizePool = round($totalPool * ($payoutPercent / 100), 2);
        $platformFee = round($totalPool - $prizePool, 2);
        $colors = allocateColors($ticket['game_mode']);
        $firstTurnPlayer = $matchPlayers[array_rand($matchPlayers)];
        
        $initialScores = [];
        foreach (array_values($matchPlayers) as $i => $p) { 
            $initialScores['player' . ($i + 1)] = 0; 
        }

        $fields = ['ticket_id', 'game_mode', 'room_code', 'entry_fee', 'prize_pool', 'platform_fee', 'status', 'current_turn_id', 'turn_started_at', 'turn_number', 'player_colors', 'scores', 'match_ends_at', 'created_at', 'updated_at'];
        $placeholders = [':tid', ':mode', ':rc', ':fee', ':pool', ':pfee', "'ready'", ':turn', 'NOW()', '0', ':colors', ':scores', 'DATE_ADD(NOW(), INTERVAL :dur MINUTE)', 'NOW()', 'NOW()'];
        $params = [
            ':tid' => $ticketId,
            ':mode' => $ticket['game_mode'],
            ':rc' => $roomCode,
            ':fee' => $entryFee,
            ':pool' => $prizePool,
            ':pfee' => $platformFee,
            ':turn' => $firstTurnPlayer['user_id'],
            ':colors' => json_encode($colors),
            ':scores' => json_encode($initialScores),
            ':dur' => intval($ticket['duration_minutes'])
        ];

        foreach ($matchPlayers as $i => $p) {
            $n = $i + 1;
            $fields[] = "player{$n}_id";
            $placeholders[] = ":p{$n}id";
            $params[":p{$n}id"] = $p['user_id'];
            $fields[] = "player{$n}_name";
            $placeholders[] = ":p{$n}name";
            $params[":p{$n}name"] = $p['username'];
        }

        $sql = "INSERT INTO matches (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $matchId = $conn->lastInsertId();

        // Update queue status
        $matchedIds = array_column($matchPlayers, 'id');
        $inPlaceholders = implode(',', array_fill(0, count($matchedIds), '?'));
        $stmt = $conn->prepare("UPDATE ticket_queue SET status = 'matched', match_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$inPlaceholders})");
        $stmt->execute(array_merge([$matchId], $matchedIds));

        $db->commit();

        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Match found!', [
            'status' => 'matched',
            'match_id' => $matchId,
            'room_code' => $roomCode,
            'csrf_token' => $newCsrf
        ]);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[tickets.php join] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// TICKET STATUS (GET)
// ==============================================
function handleStatus() {
    if (!isLoggedIn()) jsonResponse(false, 'Not authenticated', [], 401);
    $userId = getCurrentUserId();
    if (!$userId) jsonResponse(false, 'Invalid session', [], 401);

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // 🔥 FIX: join to matches so we know the CURRENT status of the match this
        // queue row points to. ticket_queue.status is set to 'matched' once and
        // never updated again — without this join, a player would be redirected
        // back into the same match forever, even long after it finished, because
        // this row still says 'matched' no matter what happened to the match.
        $stmt = $conn->prepare("
            SELECT q.id, q.status, q.match_id, q.ticket_id, t.game_mode, m.status AS match_status,
                   m.room_code,
                   (SELECT COUNT(*) FROM ticket_queue q2 WHERE q2.ticket_id = q.ticket_id AND q2.status = 'waiting') AS waiting_count 
            FROM ticket_queue q 
            JOIN game_tickets t ON q.ticket_id = t.id 
            LEFT JOIN matches m ON m.id = q.match_id
            WHERE q.user_id = :uid AND q.status IN ('waiting','matched') 
            ORDER BY q.id DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) { 
            jsonResponse(true, 'Not in queue', ['status' => 'none']); 
            return;
        }

        $requiredPlayers = $row['game_mode'] === '1vs1' ? 2 : 4;

        if ($row['status'] === 'matched' && $row['match_id']) {
            // The match this queue entry points to has already ended (or was
            // cancelled) — stop treating this player as "matched" so the
            // dashboard doesn't keep bouncing them back into a finished room.
            if (in_array($row['match_status'], ['completed', 'cancelled'], true) || $row['match_status'] === null) {
                try {
                    $upd = $conn->prepare("UPDATE ticket_queue SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                    $upd->execute([':id' => $row['id']]);
                } catch (Throwable $ignored) {
                    error_log('[tickets status] cleanup update failed: ' . $ignored->getMessage());
                }

                $newCsrf = refreshCsrfToken();
                jsonResponse(true, 'Not in queue', ['status' => 'none', 'csrf_token' => $newCsrf]);
                return;
            }

            $newCsrf = refreshCsrfToken();
            jsonResponse(true, 'Matched', [
                'status' => 'matched',
                'match_id' => intval($row['match_id']),
                'room_code' => $row['room_code'],
                'csrf_token' => $newCsrf
            ]);
            return;
        }

        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Waiting', [
            'status' => 'waiting',
            'waiting_count' => intval($row['waiting_count']),
            'players_needed' => $requiredPlayers,
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        error_log('[tickets status] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// CANCEL TICKET (POST - CSRF REQUIRED)
// ==============================================
function handleCancel() {
    if (!isLoggedIn()) jsonResponse(false, 'Not authenticated', [], 401);
    $userId = getCurrentUserId();
    if (!$userId) jsonResponse(false, 'Invalid session', [], 401);

    $input = json_decode(file_get_contents('php://input'), true);

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        $stmt = $conn->prepare("SELECT id, transaction_id FROM ticket_queue WHERE user_id = :uid AND status = 'waiting' FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) { 
            $db->rollback(); 
            jsonResponse(false, 'Not currently waiting in any queue', [], 400); 
        }

        $stmt = $conn->prepare("SELECT amount FROM transactions WHERE id = :tid FOR UPDATE");
        $stmt->execute([':tid' => $row['transaction_id']]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        $refundAmount = floatval($tx['amount'] ?? 0);

        if ($refundAmount > 0) {
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
            $stmt->execute([':amt' => $refundAmount, ':uid' => $userId]);
        }

        $stmt = $conn->prepare("UPDATE ticket_queue SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $row['id']]);

        // Record refund
        $orderId = 'TKTR-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at) VALUES (:uid, :amt, 'credit', 'refund', 'Tournament Ticket queue cancelled', :oid, 'success', 0, 0, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':uid' => $userId,
            ':amt' => $refundAmount,
            ':oid' => $orderId
        ]);

        $db->commit();
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Left queue and refunded ₹' . number_format($refundAmount, 2), [
            'refund_amount' => $refundAmount,
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[tickets cancel] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: LIST TICKETS
// ==============================================
function handleAdminList() {
    if (!isAdminLoggedIn()) jsonResponse(false, 'Admin access required', [], 401);
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT t.*, 
                   (SELECT COUNT(*) FROM ticket_queue q WHERE q.ticket_id = t.id AND q.status = 'waiting') AS waiting_count,
                   (SELECT COUNT(*) FROM matches m WHERE m.ticket_id = t.id) AS total_matches
            FROM game_tickets t 
            ORDER BY t.game_mode ASC, t.entry_fee ASC
        ");
        $stmt->execute();
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Admin tickets retrieved', ['tickets' => $tickets ?: []]);
    } catch (PDOException $e) {
        error_log('[tickets admin_list] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: CREATE TICKET
// ==============================================
function handleAdminCreate() {
    if (!isAdminLoggedIn()) jsonResponse(false, 'Admin access required', [], 401);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $gameMode = in_array($input['game_mode'] ?? '', ['1vs1', '1vs4'], true) ? $input['game_mode'] : '1vs1';
    $entryFee = floatval($input['entry_fee'] ?? 0);
    $durationMinutes = max(1, min(120, intval($input['duration_minutes'] ?? 15)));
    $payoutPercent = max(1, min(99, floatval($input['payout_percent'] ?? 70)));
    
    if ($entryFee <= 0) jsonResponse(false, 'Invalid entry fee', [], 400);
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("INSERT INTO game_tickets (game_mode, entry_fee, duration_minutes, payout_percent, is_active, created_at, updated_at) VALUES (:mode, :fee, :dur, :payout, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':mode' => $gameMode,
            ':fee' => $entryFee,
            ':dur' => $durationMinutes,
            ':payout' => $payoutPercent
        ]);
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Ticket created', [
            'ticket_id' => $conn->lastInsertId(),
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        error_log('[tickets admin_create] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: TOGGLE TICKET
// ==============================================
function handleAdminToggle() {
    if (!isAdminLoggedIn()) jsonResponse(false, 'Admin access required', [], 401);
    
    $input = json_decode(file_get_contents('php://input'), true);
    $ticketId = intval($input['ticket_id'] ?? 0);
    
    if ($ticketId <= 0) jsonResponse(false, 'Invalid ticket ID', [], 400);
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("UPDATE game_tickets SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $ticketId]);
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Ticket toggled', ['csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        error_log('[tickets admin_toggle] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>