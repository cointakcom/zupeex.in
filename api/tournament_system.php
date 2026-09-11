<?php
/**
 * ======================================================
 * TOURNAMENT_SYSTEM.PHP - Complete Tournament API (CSRF FIXED)
 * Ludo Tournament Platform - Multiplayer Tournament System
 * Version: 1.3.0 - CSRF AUTO-REFRESH SUPPORT
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

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
} catch (Exception $e) {
    error_log('Tournament DB Error: ' . $e->getMessage());
    jsonResponse(false, 'Database connection error', [], 500);
}

$userId = null;
if (isLoggedIn()) {
    $userId = getCurrentUserId();
}

if (!defined('PLATFORM_FEE')) {
    define('PLATFORM_FEE', 15);
}

// 🔥 CSRF Validation Helper with auto-refresh
function requireCsrfOrFail() {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    if (!CSRFToken::validate($token)) {
        jsonResponse(false, 'Invalid or missing CSRF token', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403);
    }
    
    return $input;
}

// 🔥 Admin CSRF Helper
function requireAdminCsrfOrFail() {
    if (!isAdminLoggedIn()) {
        jsonResponse(false, 'Admin access required', [], 403);
    }
    return requireCsrfOrFail();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list_active': 
            handleListActiveTournaments($conn); 
            break;
        case 'get_tournament': 
            handleGetTournament($conn); 
            break;
        case 'register':
            if (!$userId) { jsonResponse(false, 'Please login first', [], 401); }
            $input = requireCsrfOrFail();
            handleRegisterForTournament($conn, $userId, $input); 
            break;
        case 'my_registrations':
            if (!$userId) { jsonResponse(false, 'Please login first', [], 401); }
            handleMyRegistrations($conn, $userId); 
            break;
        case 'leaderboard': 
            handleTournamentLeaderboard($conn); 
            break;
        case 'admin_create':
            $input = requireAdminCsrfOrFail();
            handleAdminCreateTournament($conn, $input); 
            break;
        case 'admin_update':
            $input = requireAdminCsrfOrFail();
            handleAdminUpdateTournament($conn, $input); 
            break;
        case 'admin_delete':
            $input = requireAdminCsrfOrFail();
            handleAdminDeleteTournament($conn, $input); 
            break;
        case 'admin_start':
            $input = requireAdminCsrfOrFail();
            handleAdminStartTournament($conn, $input); 
            break;
        case 'admin_end':
            $input = requireAdminCsrfOrFail();
            handleAdminEndTournament($conn, $input); 
            break;
        case 'admin_distribute_prizes':
            $input = requireAdminCsrfOrFail();
            handleAdminDistributePrizes($conn, $input); 
            break;
        default: 
            jsonResponse(false, 'Invalid action', [], 400);
    }
} catch (Exception $e) {
    error_log('Tournament API Error: ' . $e->getMessage());
    jsonResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}

// ==============================================
// LIST ACTIVE TOURNAMENTS
// ==============================================
function handleListActiveTournaments($conn) {
    try {
        $stmt = $conn->query("
            SELECT t.*, 
                   (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.id) as registered_count,
                   (SELECT COUNT(*) FROM matches m WHERE m.tournament_id = t.id AND m.status IN ('ready','playing')) as active_matches
            FROM tournaments t 
            WHERE t.status IN ('scheduled', 'active', 'in_progress') 
            ORDER BY t.created_at DESC
        ");
        $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Tournaments retrieved', ['tournaments' => $tournaments ?: []]);
    } catch (PDOException $e) {
        error_log('[tournament list] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET TOURNAMENT DETAILS
// ==============================================
function handleGetTournament($conn) {
    $tournamentId = intval($_GET['id'] ?? 0);
    if ($tournamentId <= 0) { jsonResponse(false, 'Invalid tournament ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("
            SELECT t.*, 
                   u.username as created_by_name,
                   (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.id) as registered_count
            FROM tournaments t 
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.id = :id
        ");
        $stmt->execute([':id' => $tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) { jsonResponse(false, 'Tournament not found', [], 404); }
        
        // Calculate prize amounts
        $prizePool = floatval($tournament['prize_pool']);
        $tournament['calculated_first_prize'] = round($prizePool * (floatval($tournament['first_prize_percent']) / 100), 2);
        $tournament['calculated_second_prize'] = round($prizePool * (floatval($tournament['second_prize_percent']) / 100), 2);
        $tournament['calculated_third_prize'] = round($prizePool * (floatval($tournament['third_prize_percent']) / 100), 2);
        
        jsonResponse(true, 'Tournament retrieved', ['tournament' => $tournament]);
    } catch (PDOException $e) {
        error_log('[tournament get] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// REGISTER FOR TOURNAMENT
// ==============================================
function handleRegisterForTournament($conn, int $userId, array $input) {
    $tournamentId = intval($input['tournament_id'] ?? 0);
    if ($tournamentId <= 0) { jsonResponse(false, 'Invalid tournament ID', [], 400); }
    
    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("SELECT * FROM tournaments WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) { 
            $conn->rollBack(); 
            jsonResponse(false, 'Tournament not found', [], 404); 
        }
        
        if (!in_array($tournament['status'], ['scheduled', 'active'])) { 
            $conn->rollBack(); 
            jsonResponse(false, 'Tournament is not open for registration', [], 400); 
        }
        
        // Check if already registered
        $stmt = $conn->prepare("SELECT id FROM tournament_registrations WHERE tournament_id = :tid AND user_id = :uid");
        $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
        if ($stmt->fetch()) { 
            $conn->rollBack(); 
            jsonResponse(false, 'Already registered for this tournament', [], 409); 
        }
        
        // Check balance
        $entryFee = floatval($tournament['entry_fee']);
        $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || floatval($user['wallet_balance']) < $entryFee) {
            $conn->rollBack();
            jsonResponse(false, 'Insufficient wallet balance', [
                'required' => $entryFee, 
                'balance' => floatval($user['wallet_balance'] ?? 0)
            ], 400);
        }
        
        // Deduct entry fee
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
        
        // Register
        $stmt = $conn->prepare("INSERT INTO tournament_registrations (tournament_id, user_id, created_at) VALUES (:tid, :uid, CURRENT_TIMESTAMP)");
        $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
        
        $conn->commit();
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Successfully registered', ['csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('[tournament register] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// MY REGISTRATIONS
// ==============================================
function handleMyRegistrations($conn, int $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT tr.*, t.name, t.game_mode, t.entry_fee, t.prize_pool, t.status, t.tournament_code
            FROM tournament_registrations tr
            JOIN tournaments t ON tr.tournament_id = t.id
            WHERE tr.user_id = :uid
            ORDER BY tr.created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Registrations retrieved', ['registrations' => $registrations ?: []]);
    } catch (PDOException $e) {
        error_log('[tournament my_reg] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// TOURNAMENT LEADERBOARD
// ==============================================
function handleTournamentLeaderboard($conn) {
    $tournamentId = intval($_GET['tournament_id'] ?? 0);
    if ($tournamentId <= 0) { jsonResponse(false, 'Invalid tournament ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("
            SELECT tr.user_id, u.username, u.elo_rating,
                   (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = tr.user_id AND source = 'match_win' AND tournament_id = tr.tournament_id) as total_winnings
            FROM tournament_registrations tr
            JOIN users u ON tr.user_id = u.id
            WHERE tr.tournament_id = :tid
            ORDER BY total_winnings DESC, u.elo_rating DESC
            LIMIT 100
        ");
        $stmt->execute([':tid' => $tournamentId]);
        $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Leaderboard retrieved', ['leaderboard' => $leaderboard ?: []]);
    } catch (PDOException $e) {
        error_log('[tournament leaderboard] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: CREATE TOURNAMENT
// ==============================================
function handleAdminCreateTournament($conn, array $input) {
    $name = trim($input['name'] ?? '');
    $gameMode = in_array($input['game_mode'] ?? '', ['1vs1', '1vs4'], true) ? $input['game_mode'] : '1vs1';
    $entryFee = floatval($input['entry_fee'] ?? 0);
    $totalPlayers = intval($input['total_players'] ?? 100);
    $firstPrizePercent = floatval($input['first_prize_percent'] ?? 60);
    $secondPrizePercent = floatval($input['second_prize_percent'] ?? 30);
    $thirdPrizePercent = floatval($input['third_prize_percent'] ?? 10);
    $scoringMode = in_array($input['scoring_mode'] ?? 'winner_takes_all', ['winner_takes_all', 'points_timed'], true) ? $input['scoring_mode'] : 'winner_takes_all';
    
    if (empty($name) || $entryFee <= 0 || $totalPlayers < 2) {
        jsonResponse(false, 'Invalid tournament parameters', [], 400);
    }
    
    try {
        $totalPool = $entryFee * $totalPlayers;
        $platformFee = $totalPool * (PLATFORM_FEE / 100);
        $prizePool = $totalPool - $platformFee;
        $tournamentCode = 'T' . strtoupper(bin2hex(random_bytes(4)));
        
        $stmt = $conn->prepare("
            INSERT INTO tournaments (tournament_code, name, game_mode, entry_fee, prize_pool, platform_fee, max_players, total_players, min_players, first_prize_percent, second_prize_percent, third_prize_percent, first_prize_amount, second_prize_amount, third_prize_amount, scoring_mode, status, created_by, created_at, updated_at)
            VALUES (:code, :name, :mode, :fee, :prize, :pf, :max, :total, 2, :fp, :sp, :tp, :fa, :sa, :ta, :smode, 'scheduled', :admin, NOW(), NOW())
        ");
        $stmt->execute([
            ':code' => $tournamentCode,
            ':name' => $name,
            ':mode' => $gameMode,
            ':fee' => $entryFee,
            ':prize' => $prizePool,
            ':pf' => $platformFee,
            ':max' => $gameMode === '1vs1' ? 2 : 4,
            ':total' => $totalPlayers,
            ':fp' => $firstPrizePercent,
            ':sp' => $secondPrizePercent,
            ':tp' => $thirdPrizePercent,
            ':fa' => round($prizePool * ($firstPrizePercent / 100), 2),
            ':sa' => round($prizePool * ($secondPrizePercent / 100), 2),
            ':ta' => round($prizePool * ($thirdPrizePercent / 100), 2),
            ':smode' => $scoringMode,
            ':admin' => $_SESSION['admin_id']
        ]);
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Tournament created', [
            'tournament_id' => $conn->lastInsertId(),
            'tournament_code' => $tournamentCode,
            'csrf_token' => $newCsrf
        ]);
    } catch (PDOException $e) {
        error_log('[tournament admin_create] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: UPDATE TOURNAMENT STATUS
// ==============================================
function handleAdminUpdateTournament($conn, array $input) {
    $tournamentId = intval($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    
    if ($tournamentId <= 0 || !in_array($status, ['scheduled', 'active', 'in_progress', 'completed', 'cancelled'], true)) {
        jsonResponse(false, 'Invalid parameters', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("UPDATE tournaments SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $tournamentId]);
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Tournament updated', ['csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        error_log('[tournament admin_update] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: DELETE TOURNAMENT
// ==============================================
function handleAdminDeleteTournament($conn, array $input) {
    $tournamentId = intval($input['id'] ?? 0);
    if ($tournamentId <= 0) { jsonResponse(false, 'Invalid tournament ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("DELETE FROM tournaments WHERE id = :id AND status = 'scheduled'");
        $stmt->execute([':id' => $tournamentId]);
        
        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Tournament not found or cannot be deleted', [], 400);
        }
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Tournament deleted', ['csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        error_log('[tournament admin_delete] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: START TOURNAMENT
// ==============================================
function handleAdminStartTournament($conn, array $input) {
    $tournamentId = intval($input['id'] ?? 0);
    if ($tournamentId <= 0) { jsonResponse(false, 'Invalid tournament ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("UPDATE tournaments SET status = 'in_progress', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status IN ('scheduled', 'active')");
        $stmt->execute([':id' => $tournamentId]);
        
        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Tournament cannot be started', [], 400);
        }
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Tournament started', ['csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        error_log('[tournament admin_start] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: END TOURNAMENT
// ==============================================
function handleAdminEndTournament($conn, array $input) {
    $tournamentId = intval($input['id'] ?? 0);
    if ($tournamentId <= 0) { jsonResponse(false, 'Invalid tournament ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("UPDATE tournaments SET status = 'completed', completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status IN ('active', 'in_progress')");
        $stmt->execute([':id' => $tournamentId]);
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Tournament ended', ['csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        error_log('[tournament admin_end] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: DISTRIBUTE PRIZES
// ==============================================
function handleAdminDistributePrizes($conn, array $input) {
    $tournamentId = intval($input['id'] ?? 0);
    $firstWinnerId = intval($input['first_winner_id'] ?? 0);
    $secondWinnerId = intval($input['second_winner_id'] ?? 0);
    $thirdWinnerId = intval($input['third_winner_id'] ?? 0);
    
    if ($tournamentId <= 0 || $firstWinnerId <= 0) {
        jsonResponse(false, 'Invalid parameters', [], 400);
    }
    
    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("SELECT * FROM tournaments WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) {
            $conn->rollBack();
            jsonResponse(false, 'Tournament not found', [], 404);
        }
        
        $winners = [
            1 => ['id' => $firstWinnerId, 'amount' => $tournament['first_prize_amount']],
            2 => ['id' => $secondWinnerId, 'amount' => $tournament['second_prize_amount']],
            3 => ['id' => $thirdWinnerId, 'amount' => $tournament['third_prize_amount']],
        ];
        
        foreach ($winners as $position => $winner) {
            if ($winner['id'] <= 0) continue;
            
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt, total_earnings = total_earnings + :amt WHERE id = :uid");
            $stmt->execute([':amt' => $winner['amount'], ':uid' => $winner['id']]);
            
            $orderId = 'TPRIZE-' . $position . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, tournament_id, amount, type, source, description, order_id, status, created_at) VALUES (:uid, :tid, :amt, 'credit', 'tournament_prize', :desc, :oid, 'success', CURRENT_TIMESTAMP)");
            $stmt->execute([
                ':uid' => $winner['id'],
                ':tid' => $tournamentId,
                ':amt' => $winner['amount'],
                ':desc' => "Tournament prize - Position {$position}",
                ':oid' => $orderId
            ]);
        }
        
        $stmt = $conn->prepare("UPDATE tournaments SET status = 'completed', completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $tournamentId]);
        
        $conn->commit();
        
        $newCsrf = refreshCsrfToken();
        jsonResponse(true, 'Prizes distributed', ['csrf_token' => $newCsrf]);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('[tournament distribute] ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>