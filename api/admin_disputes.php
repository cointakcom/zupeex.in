<?php
/**
 * ======================================================
 * ADMIN_DISPUTES.PHP - Dispute Management API (CSRF FIXED)
 * Ludo Tournament Platform - Admin Dispute Resolution
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
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

SessionManager::init();

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
    jsonResponse(false, 'Unauthorized', [], 401);
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT u.id FROM users u
        JOIN sessions s ON u.id = s.user_id
        WHERE u.id = :aid AND u.is_admin = 1 AND u.is_active = 1
        AND s.session_token = :token AND s.is_active = 1 AND s.expires_at > NOW()
    ");
    $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
    if (!$stmt->fetch()) {
        jsonResponse(false, 'Unauthorized', [], 401);
    }
} catch (Exception $e) {
    jsonResponse(false, 'Auth error', [], 500);
}

// 🔥 CSRF Validation Helper for POST requests
function validateCsrfOrFail() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(false, 'Invalid JSON body', [], 400);
    }
    
    $providedToken = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    
    if (!$providedToken || !CSRFToken::validate($providedToken)) {
        // Return new token for auto-retry
        jsonResponse(false, 'Invalid CSRF token', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403);
    }
    
    return $input;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': 
        handleList($conn); 
        break;
    case 'get': 
        handleGet($conn); 
        break;
    case 'get_messages': 
        handleGetMessages($conn); 
        break;
    case 'add_message': 
        $input = validateCsrfOrFail();
        handleAddMessage($conn, $input); 
        break;
    case 'investigate': 
        $input = validateCsrfOrFail();
        handleInvestigate($conn, $input); 
        break;
    case 'resolve': 
        $input = validateCsrfOrFail();
        handleResolve($conn, $input); 
        break;
    case 'close': 
        $input = validateCsrfOrFail();
        handleClose($conn, $input); 
        break;
    case 'get_stats': 
        handleStats($conn); 
        break;
    case 'declare_winner': 
        $input = validateCsrfOrFail();
        handleDeclareWinner($conn, $input); 
        break;
    case 'refund_match': 
        $input = validateCsrfOrFail();
        handleRefundMatch($conn, $input); 
        break;
    default: 
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// LIST TICKETS
// ==============================================
function handleList($conn) {
    $status = $_GET['status'] ?? '';
    $limit = max(1, min(200, intval($_GET['limit'] ?? 50)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $priority = $_GET['priority'] ?? '';
    
    try {
        $where = "1=1";
        $params = [];
        if (!empty($status)) { $where .= " AND dt.status = :status"; $params[':status'] = $status; }
        if (!empty($priority)) { $where .= " AND dt.priority = :priority"; $params[':priority'] = $priority; }
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM dispute_tickets dt WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("
            SELECT dt.*, u.username as user_name, u.mobile as user_mobile,
                   opp.username as opponent_name, m.room_code, m.entry_fee, m.prize_pool,
                   m.status as match_status, m.winner_name, m.winning_amount,
                   (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = dt.id) as message_count
            FROM dispute_tickets dt
            LEFT JOIN users u ON dt.user_id = u.id
            LEFT JOIN users opp ON dt.opponent_id = opp.id
            LEFT JOIN matches m ON dt.match_id = m.id
            WHERE {$where}
            ORDER BY CASE dt.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
                     dt.created_at ASC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Tickets retrieved', [
            'tickets' => $tickets ?: [], 
            'total' => $total, 
            'limit' => $limit, 
            'offset' => $offset
        ]);
    } catch (PDOException $e) {
        error_log('[admin_disputes list] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET SINGLE TICKET
// ==============================================
function handleGet($conn) {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Invalid ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("
            SELECT dt.*, u.username as user_name, u.mobile as user_mobile,
                   u.wallet_balance as user_balance,
                   opp.username as opponent_name, m.room_code, m.entry_fee, m.prize_pool,
                   m.status as match_status, m.player1_name, m.player2_name,
                   m.winner_name, m.winning_amount,
                   resolved_by_admin.username as resolved_by_name
            FROM dispute_tickets dt
            LEFT JOIN users u ON dt.user_id = u.id
            LEFT JOIN users opp ON dt.opponent_id = opp.id
            LEFT JOIN matches m ON dt.match_id = m.id
            LEFT JOIN users resolved_by_admin ON dt.resolved_by = resolved_by_admin.id
            WHERE dt.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket) { jsonResponse(false, 'Not found', [], 404); }
        jsonResponse(true, 'Ticket retrieved', $ticket);
    } catch (PDOException $e) {
        error_log('[admin_disputes get] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET MESSAGES
// ==============================================
function handleGetMessages($conn) {
    $ticketId = intval($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) { jsonResponse(false, 'Invalid ticket ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("
            SELECT tm.*, u.username, u.mobile
            FROM ticket_messages tm
            LEFT JOIN users u ON tm.user_id = u.id
            WHERE tm.ticket_id = :tid
            ORDER BY tm.created_at ASC
        ");
        $stmt->execute([':tid' => $ticketId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, 'Messages retrieved', $messages ?: []);
    } catch (PDOException $e) {
        error_log('[admin_disputes messages] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADD MESSAGE
// ==============================================
function handleAddMessage($conn, $input) {
    $ticketId = intval($input['ticket_id'] ?? 0);
    $message = trim($input['message'] ?? '');
    
    if ($ticketId <= 0 || empty($message)) { 
        jsonResponse(false, 'Missing fields', [], 400); 
    }
    
    try {
        $stmt = $conn->prepare("SELECT id FROM dispute_tickets WHERE id = :id");
        $stmt->execute([':id' => $ticketId]);
        if (!$stmt->fetch()) { jsonResponse(false, 'Ticket not found', [], 404); }
        
        $stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin, created_at) VALUES (:tid, :uid, :msg, 1, CURRENT_TIMESTAMP)");
        $stmt->execute([':tid' => $ticketId, ':uid' => $_SESSION['admin_id'], ':msg' => $message]);
        
        jsonResponse(true, 'Message added');
    } catch (PDOException $e) {
        error_log('[admin_disputes add_msg] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// INVESTIGATE TICKET
// ==============================================
function handleInvestigate($conn, $input) {
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("UPDATE dispute_tickets SET status = 'investigating', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'open'");
        $stmt->execute([':id' => $id]);
        
        if ($stmt->rowCount() === 0) { 
            jsonResponse(false, 'Ticket not found or not open', [], 400); 
        }
        
        $stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin, created_at) VALUES (:tid, :uid, 'Investigation started by admin', 1, CURRENT_TIMESTAMP)");
        $stmt->execute([':tid' => $id, ':uid' => $_SESSION['admin_id']]);
        
        jsonResponse(true, 'Ticket under investigation');
    } catch (PDOException $e) {
        error_log('[admin_disputes investigate] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// RESOLVE TICKET
// ==============================================
function handleResolve($conn, $input) {
    $id = intval($input['id'] ?? 0);
    $resolutionType = $input['resolution_type'] ?? 'no_action';
    $resolutionNotes = trim($input['resolution_notes'] ?? '');
    $refundAmount = floatval($input['refund_amount'] ?? 0);
    $winnerId = intval($input['winner_id'] ?? 0);
    
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    $validTypes = ['winner_declared', 'refund', 'cancelled', 'replay', 'no_action'];
    if (!in_array($resolutionType, $validTypes)) { 
        jsonResponse(false, 'Invalid resolution type', [], 400); 
    }
    
    try {
        $stmt = $conn->prepare("SELECT user_id, match_id, status FROM dispute_tickets WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket || !in_array($ticket['status'], ['open', 'investigating'])) {
            jsonResponse(false, 'Ticket not found or cannot be resolved', [], 400);
        }
        
        // Refund logic
        if ($resolutionType === 'refund' && $refundAmount > 0) {
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid");
            $stmt->execute([':amt' => $refundAmount, ':uid' => $ticket['user_id']]);
            
            $orderId = 'DISPUTE-REFUND-' . strtoupper(uniqid());
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, source, description, order_id, status, created_at) VALUES (:uid, :amt, 'credit', 'refund', :desc, :oid, 'success', CURRENT_TIMESTAMP)");
            $stmt->execute([
                ':uid' => $ticket['user_id'], 
                ':amt' => $refundAmount, 
                ':desc' => "Dispute refund #{$id}", 
                ':oid' => $orderId
            ]);
        }
        
        // Winner declared logic
        if ($resolutionType === 'winner_declared' && $winnerId > 0) {
            $stmt = $conn->prepare("UPDATE matches SET winner_id = :wid, status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = :mid");
            $stmt->execute([':wid' => $winnerId, ':mid' => $ticket['match_id']]);
        }
        
        // Update ticket
        $stmt = $conn->prepare("UPDATE dispute_tickets SET status = 'resolved', resolution_type = :rtype, resolution_notes = :notes, refund_amount = :refund, resolved_by = :admin_id, resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([
            ':rtype' => $resolutionType, 
            ':notes' => $resolutionNotes, 
            ':refund' => $refundAmount, 
            ':admin_id' => $_SESSION['admin_id'], 
            ':id' => $id
        ]);
        
        $stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin, created_at) VALUES (:tid, :uid, :msg, 1, CURRENT_TIMESTAMP)");
        $stmt->execute([':tid' => $id, ':uid' => $_SESSION['admin_id'], ':msg' => "✅ Resolved: " . strtoupper($resolutionType)]);
        
        jsonResponse(true, 'Ticket resolved');
    } catch (PDOException $e) {
        error_log('[admin_disputes resolve] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// CLOSE TICKET
// ==============================================
function handleClose($conn, $input) {
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Missing ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("UPDATE dispute_tickets SET status = 'closed', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'resolved'");
        $stmt->execute([':id' => $id]);
        
        if ($stmt->rowCount() === 0) { 
            jsonResponse(false, 'Ticket not found or not resolved', [], 400); 
        }
        
        jsonResponse(true, 'Ticket closed');
    } catch (PDOException $e) {
        error_log('[admin_disputes close] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET STATS
// ==============================================
function handleStats($conn) {
    try {
        $stats = [];
        foreach (['open', 'investigating', 'resolved', 'closed'] as $s) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM dispute_tickets WHERE status = :s");
            $stmt->execute([':s' => $s]);
            $stats[$s] = intval($stmt->fetchColumn());
        }
        $stats['total'] = array_sum($stats);
        
        $stmt = $conn->query("SELECT COUNT(*) FROM dispute_tickets WHERE priority IN ('high','urgent') AND status IN ('open','investigating')");
        $stats['high_priority'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COALESCE(SUM(refund_amount), 0) FROM dispute_tickets WHERE status = 'resolved' AND resolution_type = 'refund'");
        $stats['total_refunds'] = floatval($stmt->fetchColumn());
        
        jsonResponse(true, 'Stats retrieved', $stats);
    } catch (PDOException $e) {
        error_log('[admin_disputes stats] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// DECLARE WINNER
// ==============================================
function handleDeclareWinner($conn, $input) {
    $matchId = intval($input['match_id'] ?? 0);
    $winnerId = intval($input['winner_id'] ?? 0);
    
    if ($matchId <= 0 || $winnerId <= 0) { 
        jsonResponse(false, 'Missing fields', [], 400); 
    }
    
    try {
        $stmt = $conn->prepare("SELECT entry_fee, prize_pool, status, player1_id, player2_id FROM matches WHERE id = :mid");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match || $match['status'] === 'completed') { 
            jsonResponse(false, 'Match not found or already completed', [], 400); 
        }
        
        if (!in_array($winnerId, [$match['player1_id'], $match['player2_id']])) { 
            jsonResponse(false, 'Winner not in match', [], 400); 
        }
        
        $prizePool = floatval($match['prize_pool']);
        
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :prize, total_earnings = total_earnings + :prize, total_matches_won = total_matches_won + 1 WHERE id = :wid");
        $stmt->execute([':prize' => $prizePool, ':wid' => $winnerId]);
        
        $stmt = $conn->prepare("UPDATE matches SET winner_id = :wid, winning_amount = :prize, status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = :mid");
        $stmt->execute([':wid' => $winnerId, ':prize' => $prizePool, ':mid' => $matchId]);
        
        jsonResponse(true, 'Winner declared and prize credited');
    } catch (PDOException $e) {
        error_log('[admin_disputes winner] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// REFUND MATCH
// ==============================================
function handleRefundMatch($conn, $input) {
    $matchId = intval($input['match_id'] ?? 0);
    if ($matchId <= 0) { jsonResponse(false, 'Missing match ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("SELECT entry_fee, player1_id, player2_id, status FROM matches WHERE id = :mid");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { jsonResponse(false, 'Not found', [], 404); }
        
        $players = array_filter([$match['player1_id'], $match['player2_id']]);
        $refundAmount = $match['entry_fee'];
        
        foreach ($players as $pid) {
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :pid");
            $stmt->execute([':amt' => $refundAmount, ':pid' => $pid]);
        }
        
        $stmt = $conn->prepare("UPDATE matches SET status = 'cancelled', completed_at = CURRENT_TIMESTAMP WHERE id = :mid");
        $stmt->execute([':mid' => $matchId]);
        
        jsonResponse(true, 'Match refunded', [
            'players_refunded' => count($players), 
            'amount_per_player' => $refundAmount
        ]);
    } catch (PDOException $e) {
        error_log('[admin_disputes refund] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>