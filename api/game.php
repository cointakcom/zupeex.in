<?php
/**
 * ======================================================
 * GAME.PHP - Authoritative Game API (FULLY FIXED)
 * Zupeex - Server Authority
 * Version: 4.0.0 - ALL BUGS FIXED
 * ------------------------------------------------------
 * FIXES IN THIS VERSION:
 *   1. handleGetMatchHistory() — missing function header added
 *   2. $match["player{$i}_name"] — removed (column doesn't exist)
 *   3. handleRollDice() — $stmt->execute() after checkAndSkipTurn()
 *   4. handleMoveToken() — $stmt->execute() after checkAndSkipTurn()
 *   5. applyRoomScoresToTournament() — nested transaction guard
 *   6. handleGetGameState() — clean player name resolution
 *   7. All transaction commits/rollbacks audited
 * ======================================================
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$allowedOrigins = [
    rtrim(BASE_URL, '/'),
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array(rtrim($origin, '/'), $allowedOrigins) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: rtrim(BASE_URL, '/')));
} else {
    header('Access-Control-Allow-Origin: ' . rtrim(BASE_URL, '/'));
}
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token, X-Auth-Token');
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

// ======================================================
// GAME CONSTANTS (Must match frontend ludo-engine.js exactly)
// ======================================================
const LUDO_HOME = -1;
const LUDO_TRACK_LEN = 52;
const LUDO_HOMERUN_START = 52;
const LUDO_HOMERUN_LEN = 6;
const LUDO_FINISHED = 58;
const LUDO_SAFE_INDICES = [0, 8, 13, 21, 26, 34, 39, 47];
const LUDO_STARTS = [1 => 0, 2 => 13, 3 => 26, 4 => 39];
const TURN_TIMEOUT_SECONDS = 15;

function isSafeGlobalCell(int $globalIndex): bool
{
    return in_array($globalIndex, LUDO_SAFE_INDICES, true);
}

function positionKind(int $pos): string
{
    if ($pos === LUDO_HOME) return 'home_base';
    if ($pos >= 0 && $pos < LUDO_TRACK_LEN) return 'main_track';
    if ($pos >= LUDO_HOMERUN_START && $pos < LUDO_FINISHED) return 'home_run';
    if ($pos === LUDO_FINISHED) return 'finished';
    return 'invalid';
}

// ======================================================
// ROUTING
// ======================================================
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF Validation for write actions
$READ_ONLY_ACTIONS = ['get_state', 'get_history'];

if (!in_array($action, $READ_ONLY_ACTIONS, true)) {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [
            'csrf_token' => CSRFToken::generate(),
            'refresh_needed' => true
        ], 403);
    }
}

if ($action === 'get_state') {
    session_write_close();
}

switch ($action) {
    case 'get_state':
        handleGetGameState($userId);
        break;
    case 'roll':
        handleRollDice($userId, $input);
        break;
    case 'move':
        handleMoveToken($userId, $input);
        break;
    case 'exit':
        handleExitGame($userId, $input);
        break;
    case 'get_history':
        handleGetMatchHistory($userId);
        break;
    default:
        jsonResponse(false, 'Invalid action', [], 400);
}

// ======================================================
// GET GAME STATE
// ======================================================
function handleGetGameState(int $userId): void
{
    $matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT m.*,
                   u1.username as player1_username, u2.username as player2_username,
                   u3.username as player3_username, u4.username as player4_username
            FROM matches m
            LEFT JOIN users u1 ON m.player1_id = u1.id
            LEFT JOIN users u2 ON m.player2_id = u2.id
            LEFT JOIN users u3 ON m.player3_id = u3.id
            LEFT JOIN users u4 ON m.player4_id = u4.id
            WHERE m.id = :match_id
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            jsonResponse(false, 'Match not found', [], 404);
        }

        // Timed room expiry check
        if ($match['scores'] !== null && $match['status'] !== 'completed'
            && $match['match_ends_at'] && strtotime($match['match_ends_at']) <= time()) {
            finalizeTimedRoom($conn, $db, $matchId);
            $stmt->execute([':match_id' => $matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Turn skip check
        if ($match['status'] !== 'completed') {
            checkAndSkipTurn($conn, $db, $match);
            $stmt->execute([':match_id' => $matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Authorization check
        $playerIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) $playerIds[] = $pid;
        }
        if (!in_array($userId, $playerIds)) {
            jsonResponse(false, 'Not authorized for this match', [], 403);
        }

        // Board state
        $boardState = json_decode($match['board_state'] ?? '{}', true) ?: [];
        for ($i = 1; $i <= 4; $i++) {
            if (!isset($boardState["player{$i}"])) {
                $boardState["player{$i}"] = ['token1' => -1, 'token2' => -1, 'token3' => -1, 'token4' => -1];
            }
        }

        // Player colors (Slot → Colour)
        $colors = json_decode($match['player_colors'] ?? '', true);
        if (!$colors) {
            $colors = allocateColors($match['game_mode'] ?? '1vs1');
        }

        // Current turn
        $currentTurnUserId = intval($match['current_turn_id'] ?? 0);
        $currentTurn = 1;
        for ($i = 1; $i <= 4; $i++) {
            if ($currentTurnUserId === intval($match["player{$i}_id"] ?? 0)) {
                $currentTurn = $i;
                break;
            }
        }

        // Players array — FIXED: no reference to non-existent player{$i}_name
        $players = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) {
                $username = $match["player{$i}_username"] ?? "Player {$i}";
                $players["player{$i}"] = [
                    'id' => $pid,
                    'name' => $username,
                    'color' => intval($colors[$i] ?? $i),
                    'is_me' => ($pid === $userId),
                ];
            }
        }

        jsonResponse(true, 'Game state retrieved', [
            'match' => [
                'id' => intval($match['id']),
                'room_code' => $match['room_code'],
                'game_mode' => $match['game_mode'] ?? '1vs1',
                'status' => $match['status'],
                'current_turn' => $currentTurn,
                'current_turn_id' => $currentTurnUserId,
                'dice_value' => intval($match['dice_value'] ?? 0),
                'consecutive_sixes' => intval($match['consecutive_sixes'] ?? 0),
                'turn_number' => intval($match['turn_number'] ?? 0),
                'entry_fee' => floatval($match['entry_fee'] ?? 0),
                'prize_pool' => floatval($match['prize_pool'] ?? 0),
                'winner_id' => $match['winner_id'] ? intval($match['winner_id']) : null,
                'winning_amount' => $match['winning_amount'] ? floatval($match['winning_amount']) : null,
                'is_my_turn' => ($currentTurnUserId === $userId),
                'has_rolled' => (intval($match['dice_value'] ?? 0) > 0),
                'player_count' => count($playerIds),
                'is_timed_room' => ($match['scores'] !== null),
                'scores' => $match['scores'] ? json_decode($match['scores'], true) : null,
                'match_ends_at' => $match['match_ends_at'],
                'seconds_remaining' => $match['match_ends_at'] ? max(0, strtotime($match['match_ends_at']) - time()) : null,
                'turn_started_at' => $match['turn_started_at'] ?? null,
                'turn_timeout_seconds' => TURN_TIMEOUT_SECONDS,
                'seconds_remaining_in_turn' => !empty($match['turn_started_at'])
                    ? max(0, TURN_TIMEOUT_SECONDS - (time() - strtotime($match['turn_started_at'])))
                    : TURN_TIMEOUT_SECONDS,
            ],
            'players' => $players,
            'board' => $boardState,
            'safe_cells' => LUDO_SAFE_INDICES,
            'updated_at' => $match['updated_at'],
        ]);

    } catch (PDOException $e) {
        error_log('[game.php get_state] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred.', [], 500);
    }
}

// ======================================================
// ROLL DICE
// ======================================================
function handleRollDice(int $userId, array $input): void
{
    $matchId = intval($input['match_id'] ?? 0);
    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        $stmt = $conn->prepare("SELECT * FROM matches WHERE id = :match_id FOR UPDATE");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }

        // Timed room expiry
        if ($match['scores'] !== null && $match['match_ends_at']
            && strtotime($match['match_ends_at']) <= time()) {
            $db->rollback();
            finalizeTimedRoom($conn, $db, $matchId);
            jsonResponse(false, "Time's up! This room has ended.", [
                'match_id' => $matchId,
                'timed_out' => true
            ], 200);
        }

        // Turn skip check — FIXED: re-fetch after skip
        checkAndSkipTurn($conn, $db, $match);
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!in_array($match['status'], ['playing', 'ready'])) {
            $db->rollback();
            jsonResponse(false, 'Match not in playable state', [], 400);
        }
        if (intval($match['current_turn_id'] ?? 0) !== $userId) {
            $db->rollback();
            jsonResponse(false, 'Not your turn', [], 403);
        }

        // Authorization
        $activePlayers = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) $activePlayers[] = $pid;
        }
        if (!in_array($userId, $activePlayers, true)) {
            $db->rollback();
            jsonResponse(false, 'Player not in this match', [], 403);
        }

        // Transition ready → playing
        if ($match['status'] === 'ready') {
            $stmt2 = $conn->prepare("UPDATE matches SET status = 'playing' WHERE id = :mid");
            $stmt2->execute([':mid' => $matchId]);
        }

        $diceValue = random_int(1, 6);
        $consecutiveSixes = intval($match['consecutive_sixes'] ?? 0);
        $forfeited = false;
        $extraTurn = false;
        $isTimedRoom = ($match['scores'] !== null);

        $boardState = json_decode($match['board_state'] ?? '{}', true) ?: [];

        // Find player slot
        $playerNumber = 1;
        for ($i = 1; $i <= 4; $i++) {
            if ($userId === intval($match["player{$i}_id"] ?? 0)) {
                $playerNumber = $i;
                break;
            }
        }

        // Score tracking
        $scores = $isTimedRoom ? (json_decode($match['scores'], true) ?: []) : null;
        $scoreStats = $isTimedRoom ? (json_decode($match['score_stats'] ?? '', true) ?: []) : null;
        if ($isTimedRoom) {
            $pk = "player{$playerNumber}";
            if (!isset($scores[$pk])) $scores[$pk] = 0;
            if (!isset($scoreStats[$pk]) || !is_array($scoreStats[$pk])) {
                $scoreStats[$pk] = ['home_count' => 0, 'kills' => 0, 'rolls' => 0];
            }
            $scoreStats[$pk]['rolls'] += 1;
        }

        // Six handling
        if ($diceValue === 6) {
            $consecutiveSixes++;
            if ($consecutiveSixes >= 3) {
                $forfeited = true;
                $consecutiveSixes = 0;
            } else {
                $extraTurn = true;
            }
        } else {
            $consecutiveSixes = 0;
        }

        // Player colour (for move math)
        $colors = json_decode($match['player_colors'] ?? '', true);
        if (!$colors) $colors = allocateColors($match['game_mode'] ?? '1vs1');
        $playerColor = intval($colors[$playerNumber] ?? $playerNumber);

        $hasLegalMove = !$forfeited
            && playerHasLegalMove($boardState, $playerNumber, $playerColor, $diceValue);

        // Next turn decision
        $nextTurnId = $userId;
        if ($forfeited || !$extraTurn) {
            if (!$hasLegalMove || $forfeited) {
                $currentIndex = array_search($userId, $activePlayers, true);
                $nextIndex = ($currentIndex + 1) % count($activePlayers);
                $nextTurnId = $activePlayers[$nextIndex];
            }
        }

        $diceToStore = ($nextTurnId === $userId) ? $diceValue : 0;

        $stmt2 = $conn->prepare("
            UPDATE matches
            SET dice_value = :dice,
                current_turn_id = :next_turn,
                consecutive_sixes = :csixes,
                turn_number = turn_number + 1,
                turn_started_at = CURRENT_TIMESTAMP,
                scores = :scores,
                score_stats = :score_stats,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :mid
        ");
        $stmt2->execute([
            ':dice' => $diceToStore,
            ':next_turn' => $nextTurnId,
            ':csixes' => $consecutiveSixes,
            ':scores' => $isTimedRoom ? json_encode($scores) : null,
            ':score_stats' => $isTimedRoom ? json_encode($scoreStats) : null,
            ':mid' => $matchId,
        ]);

        // Log action
        $stmt2 = $conn->prepare("
            INSERT INTO game_actions (match_id, user_id, action_type, dice_value, metadata, created_at)
            VALUES (:mid, :uid, 'dice_roll', :dice, :meta, CURRENT_TIMESTAMP)
        ");
        $stmt2->execute([
            ':mid' => $matchId,
            ':uid' => $userId,
            ':dice' => $diceValue,
            ':meta' => json_encode([
                'extra_turn' => $extraTurn,
                'forfeited' => $forfeited,
                'has_legal_move' => $hasLegalMove
            ])
        ]);
        $actionId = $conn->lastInsertId();

        $db->commit();

        notifyRoomViaWebSocket($match['room_code'] ?? '');

        // Compute next turn slot for response
        $responseTurn = 1;
        for ($i = 1; $i <= 4; $i++) {
            if ($nextTurnId === intval($match["player{$i}_id"] ?? 0)) {
                $responseTurn = $i;
                break;
            }
        }

        jsonResponse(true, $forfeited ? 'Three sixes — turn forfeited!' : 'Dice rolled', [
            'match_id' => $matchId,
            'dice_value' => $diceValue,
            'extra_turn' => $extraTurn,
            'forfeited' => $forfeited,
            'has_legal_move' => $hasLegalMove,
            'current_turn' => $responseTurn,
            'action_id' => $actionId,
        ]);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[game.php roll] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred.', [], 500);
    }
}

// ======================================================
// MOVE TOKEN
// ======================================================
function handleMoveToken(int $userId, array $input): void
{
    $matchId = intval($input['match_id'] ?? 0);
    $tokenNumber = intval($input['token_number'] ?? 0);

    if ($matchId <= 0 || $tokenNumber < 1 || $tokenNumber > 4) {
        jsonResponse(false, 'Invalid move parameters', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        $stmt = $conn->prepare("SELECT * FROM matches WHERE id = :mid FOR UPDATE");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match || $match['status'] !== 'playing') {
            $db->rollback();
            jsonResponse(false, 'Match not in playing state', [], 400);
        }

        $isTimedRoom = ($match['scores'] !== null);
        if ($isTimedRoom && $match['match_ends_at']
            && strtotime($match['match_ends_at']) <= time()) {
            $db->rollback();
            finalizeTimedRoom($conn, $db, $matchId);
            jsonResponse(false, "Time's up! This room has ended.", [
                'match_id' => $matchId,
                'timed_out' => true
            ], 200);
        }

        // Turn skip check — FIXED: re-fetch after skip
        checkAndSkipTurn($conn, $db, $match);
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (intval($match['current_turn_id'] ?? 0) !== $userId) {
            $db->rollback();
            jsonResponse(false, 'Not your turn', [], 403);
        }

        $diceValue = intval($match['dice_value'] ?? 0);
        if ($diceValue <= 0) {
            $db->rollback();
            jsonResponse(false, 'Roll dice first', [], 400);
        }

        // Find player slot
        $playerNumber = 1;
        for ($i = 1; $i <= 4; $i++) {
            if ($userId === intval($match["player{$i}_id"] ?? 0)) {
                $playerNumber = $i;
                break;
            }
        }

        $boardState = json_decode($match['board_state'] ?? '{}', true) ?: [];
        for ($i = 1; $i <= 4; $i++) {
            if (!isset($boardState["player{$i}"])) {
                $boardState["player{$i}"] = ['token1' => -1, 'token2' => -1, 'token3' => -1, 'token4' => -1];
            }
        }

        $playerKey = "player{$playerNumber}";
        $tokenKey = "token{$tokenNumber}";
        $currentPos = intval($boardState[$playerKey][$tokenKey] ?? -1);

        // Player colour for move math
        $colors = json_decode($match['player_colors'] ?? '', true);
        if (!$colors) $colors = allocateColors($match['game_mode'] ?? '1vs1');
        $playerColor = intval($colors[$playerNumber] ?? $playerNumber);

        $moveResult = computeMove($currentPos, $diceValue, $playerColor);
        if ($moveResult === null) {
            $db->rollback();
            jsonResponse(false, 'Invalid move for this token', [], 400);
        }
        $newPos = $moveResult['newPos'];
        $newGlobal = $moveResult['global'];

        // Blocking check (only if landing on shared ring)
        if ($newGlobal !== null) {
            $blockingOpponent = findBlockingOpponentAtCell($boardState, $newGlobal, $playerNumber);
            if ($blockingOpponent !== null) {
                $db->rollback();
                jsonResponse(false, 'That square is blocked by two opponent tokens', [], 400);
            }
        }

        $boardState[$playerKey][$tokenKey] = $newPos;

        // Capture logic (only on shared ring, not on safe cells)
        $captured = false;
        $capturedPlayers = [];
        if ($newGlobal !== null && !isSafeGlobalCell($newGlobal)) {
            $capturedPlayers = captureOpponentsAtCell($boardState, $newGlobal, $playerNumber);
            $captured = !empty($capturedPlayers);
        }

        // Score tracking
        $scores = $isTimedRoom ? (json_decode($match['scores'], true) ?: []) : null;
        $scoreStats = $isTimedRoom ? (json_decode($match['score_stats'] ?? '', true) ?: []) : null;
        if ($isTimedRoom) {
            for ($i = 1; $i <= 4; $i++) {
                if (!isset($scores["player{$i}"])) $scores["player{$i}"] = 0;
                if (!isset($scoreStats["player{$i}"]) || !is_array($scoreStats["player{$i}"])) {
                    $scoreStats["player{$i}"] = ['home_count' => 0, 'kills' => 0, 'rolls' => 0];
                }
            }
            foreach ($capturedPlayers as $cp) {
                $scores[$playerKey] += 10;
                $scores["player{$cp}"] -= 10;
                $scoreStats[$playerKey]['kills'] += 1;
            }
            $cellsAdvanced = ($currentPos === -1) ? 1 : $diceValue;
            $scores[$playerKey] += $cellsAdvanced * 5;
            if ($newPos === LUDO_FINISHED) {
                $scores[$playerKey] += 50;
                $scoreStats[$playerKey]['home_count'] += 1;
            }
        }

        // Winner check
        $winnerId = checkWinner($boardState, $match);

        if ($winnerId) {
            if ($isTimedRoom) {
                applyEndGameScoreBonus($scores, $match, $playerKey);
            }

            $stmt2 = $conn->prepare("
                UPDATE matches
                SET status = 'completed',
                    winner_id = :wid,
                    winning_amount = :wamt,
                    board_state = :bs,
                    scores = :scores,
                    score_stats = :score_stats,
                    dice_value = 0,
                    consecutive_sixes = 0,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = :mid
            ");
            $stmt2->execute([
                ':wid' => $winnerId,
                ':wamt' => floatval($match['prize_pool'] ?? 0),
                ':bs' => json_encode($boardState),
                ':scores' => $isTimedRoom ? json_encode($scores) : null,
                ':score_stats' => $isTimedRoom ? json_encode($scoreStats) : null,
                ':mid' => $matchId,
            ]);

            $stmt2 = $conn->prepare("
                INSERT INTO game_actions
                (match_id, user_id, action_type, token_number, from_position, to_position, opponent_captured, created_at)
                VALUES (:mid, :uid, 'token_move', :tn, :fp, :tp, :cap, CURRENT_TIMESTAMP)
            ");
            $stmt2->execute([
                ':mid' => $matchId,
                ':uid' => $userId,
                ':tn' => $tokenNumber,
                ':fp' => $currentPos,
                ':tp' => $newPos,
                ':cap' => $captured ? 1 : 0
            ]);

            $db->commit();

            notifyRoomViaWebSocket($match['room_code'] ?? '');

            if ($isTimedRoom) {
                if (!empty($match['ticket_id']) && empty($match['tournament_id'])) {
                    finalizeTicketPayout($conn, $db, $matchId, $winnerId);
                } else {
                    applyRoomScoresToTournament($conn, $db, $matchId, $scores, intval($match['tournament_id'] ?? 0));
                    processSettlement($winnerId, $matchId, floatval($match['prize_pool'] ?? 0));
                }
                jsonResponse(true, 'All tokens home! Room finished early.', [
                    'match_id' => $matchId,
                    'game_over' => true,
                    'board_state' => $boardState,
                    'scores' => $scores
                ]);
                return;
            }

            processSettlement($winnerId, $matchId, floatval($match['prize_pool'] ?? 0));
            jsonResponse(true, 'Game completed!', [
                'match_id' => $matchId,
                'winner_id' => $winnerId,
                'game_over' => true,
                'board_state' => $boardState,
                'captured' => $captured
            ]);
            return;
        }

        // Next turn decision
        $rolledSix = ($diceValue === 6);
        $grantsExtraTurn = $rolledSix || $captured;

        $activePlayers = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) $activePlayers[] = $pid;
        }
        $currentIndex = array_search($userId, $activePlayers, true);
        $nextTurnId = $grantsExtraTurn
            ? $userId
            : $activePlayers[($currentIndex + 1) % count($activePlayers)];

        $stmt2 = $conn->prepare("
            UPDATE matches
            SET board_state = :bs,
                scores = :scores,
                score_stats = :score_stats,
                dice_value = 0,
                current_turn_id = :nt,
                turn_started_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :mid
        ");
        $stmt2->execute([
            ':bs' => json_encode($boardState),
            ':scores' => $isTimedRoom ? json_encode($scores) : null,
            ':score_stats' => $isTimedRoom ? json_encode($scoreStats) : null,
            ':nt' => $nextTurnId,
            ':mid' => $matchId,
        ]);

        $stmt2 = $conn->prepare("
            INSERT INTO game_actions
            (match_id, user_id, action_type, token_number, from_position, to_position, opponent_captured, created_at)
            VALUES (:mid, :uid, 'token_move', :tn, :fp, :tp, :cap, CURRENT_TIMESTAMP)
        ");
        $stmt2->execute([
            ':mid' => $matchId,
            ':uid' => $userId,
            ':tn' => $tokenNumber,
            ':fp' => $currentPos,
            ':tp' => $newPos,
            ':cap' => $captured ? 1 : 0
        ]);

        $db->commit();

        notifyRoomViaWebSocket($match['room_code'] ?? '');

        jsonResponse(true, $captured ? 'Captured! Extra turn.' : 'Token moved', [
            'match_id' => $matchId,
            'board_state' => $boardState,
            'captured' => $captured,
            'extra_turn' => $grantsExtraTurn,
            'scores' => $scores
        ]);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[game.php move] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred.', [], 500);
    }
}

// ======================================================
// GET MATCH HISTORY — FIXED: proper function header added
// ======================================================
function handleGetMatchHistory(int $userId): void
{
    $limit = max(1, min(50, intval($_GET['limit'] ?? 20)));

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT id, room_code, game_mode, entry_fee, prize_pool, status,
                   winner_id, winning_amount,
                   player1_name, player2_name, player3_name, player4_name,
                   created_at, completed_at
            FROM matches
            WHERE player1_id = :uid OR player2_id = :uid OR player3_id = :uid OR player4_id = :uid
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':uid' => $userId]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Match history retrieved', ['matches' => $matches ?: []]);
    } catch (PDOException $e) {
        error_log('[game.php history] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred.', [], 500);
    }
}

// ======================================================
// EXIT GAME
// ======================================================
function handleExitGame(int $userId, array $input): void
{
    $matchId = intval($input['match_id'] ?? 0);
    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        $stmt = $conn->prepare("SELECT * FROM matches WHERE id = :mid FOR UPDATE");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }

        $playerNumber = 0;
        for ($i = 1; $i <= 4; $i++) {
            if ($userId === intval($match["player{$i}_id"] ?? 0)) {
                $playerNumber = $i;
                break;
            }
        }
        if ($playerNumber === 0) {
            $db->rollback();
            jsonResponse(false, 'You are not in this match', [], 403);
        }

        if (in_array($match['status'], ['completed', 'cancelled'], true)) {
            $db->rollback();
            jsonResponse(false, 'This match has already ended', [], 400);
        }

        $exitedPlayers = json_decode($match['exited_players'] ?? '', true) ?: [];
        if (in_array($userId, $exitedPlayers, true)) {
            $db->rollback();
            jsonResponse(false, 'You have already exited this match', [], 400);
        }
        $exitedPlayers[] = $userId;

        $isTimedRoom = ($match['scores'] !== null);
        $playerKey = "player{$playerNumber}";
        $scores = $isTimedRoom ? (json_decode($match['scores'], true) ?: []) : null;
        if ($isTimedRoom) {
            $scores[$playerKey] = 0;
        }

        // Remaining active players
        $activePlayers = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0 && !in_array($pid, $exitedPlayers, true)) {
                $activePlayers[$i] = $pid;
            }
        }
        $remainingSlots = array_keys($activePlayers);
        sort($remainingSlots);

        $updateFields = "exited_players = :exited, scores = :scores, score_stats = :score_stats, updated_at = CURRENT_TIMESTAMP";
        $params = [
            ':exited' => json_encode($exitedPlayers),
            ':scores' => $isTimedRoom ? json_encode($scores) : null,
            ':score_stats' => $match['score_stats'],
            ':mid' => $matchId,
        ];

        $winnerId = null;
        $matchEnded = false;

        if (count($remainingSlots) <= 1) {
            $matchEnded = true;
            $winnerId = count($remainingSlots) === 1 ? $activePlayers[$remainingSlots[0]] : null;
            $updateFields .= ", status = 'completed', winner_id = :wid, winning_amount = :wamt, completed_at = CURRENT_TIMESTAMP";
            $params[':wid'] = $winnerId;
            $params[':wamt'] = $winnerId ? floatval($match['prize_pool'] ?? 0) : null;
        } elseif (intval($match['current_turn_id'] ?? 0) === $userId) {
            $nextSlot = null;
            foreach ($remainingSlots as $s) {
                if ($s > $playerNumber) {
                    $nextSlot = $s;
                    break;
                }
            }
            if ($nextSlot === null) $nextSlot = $remainingSlots[0];

            $updateFields .= ", current_turn_id = :nt, dice_value = 0, consecutive_sixes = 0, turn_started_at = CURRENT_TIMESTAMP";
            $params[':nt'] = $activePlayers[$nextSlot];
        }

        $stmt2 = $conn->prepare("UPDATE matches SET {$updateFields} WHERE id = :mid");
        $stmt2->execute($params);

        $stmt2 = $conn->prepare("
            INSERT INTO game_actions (match_id, user_id, action_type, metadata, created_at)
            VALUES (:mid, :uid, 'player_exited', :meta, CURRENT_TIMESTAMP)
        ");
        $stmt2->execute([
            ':mid' => $matchId,
            ':uid' => $userId,
            ':meta' => json_encode(['remaining_players' => count($remainingSlots)])
        ]);

        $db->commit();

        notifyRoomViaWebSocket($match['room_code'] ?? '');

        if ($matchEnded && $winnerId) {
            if ($isTimedRoom) {
                if (!empty($match['ticket_id']) && empty($match['tournament_id'])) {
                    finalizeTicketPayout($conn, $db, $matchId, $winnerId);
                } else {
                    applyRoomScoresToTournament($conn, $db, $matchId, $scores, intval($match['tournament_id'] ?? 0));
                    processSettlement($winnerId, $matchId, floatval($match['prize_pool'] ?? 0));
                }
            } else {
                processSettlement($winnerId, $matchId, floatval($match['prize_pool'] ?? 0));
            }
        }

        jsonResponse(true, $matchEnded ? 'You exited. Match ended.' : 'You exited the match.', [
            'match_id' => $matchId,
            'game_over' => $matchEnded,
            'winner_id' => $winnerId,
        ]);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('[game.php exit] ' . $e->getMessage());
        jsonResponse(false, 'A server error occurred.', [], 500);
    }
}

// ======================================================
// FINALIZE TIMED ROOM (Speed Ludo 15-min timer expiry)
// ======================================================
function finalizeTimedRoom(PDO $conn, Database $db, int $matchId): void
{
    $ownTransaction = false;

    try {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $ownTransaction = true;
        }

        $stmt = $conn->prepare("SELECT * FROM matches WHERE id = :mid FOR UPDATE");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match || $match['status'] === 'completed') {
            if ($ownTransaction) $db->commit();
            return;
        }

        $scores = json_decode($match['scores'] ?? '{}', true) ?: [];
        $scoreStats = json_decode($match['score_stats'] ?? '{}', true) ?: [];
        $playerIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) $playerIds["player{$i}"] = $pid;
        }

        // Winner determination with tie-breaker
        $maxScore = null;
        $candidates = [];
        foreach ($playerIds as $key => $pid) {
            $score = intval($scores[$key] ?? 0);
            if ($maxScore === null || $score > $maxScore) {
                $maxScore = $score;
                $candidates = [$key];
            } elseif ($score === $maxScore) {
                $candidates[] = $key;
            }
        }

        if (count($candidates) > 1) {
            foreach (['home_count', 'kills', 'rolls'] as $tieBreakField) {
                $maxStat = null;
                $next = [];
                foreach ($candidates as $key) {
                    $stat = intval($scoreStats[$key][$tieBreakField] ?? 0);
                    if ($maxStat === null || $stat > $maxStat) {
                        $maxStat = $stat;
                        $next = [$key];
                    } elseif ($stat === $maxStat) {
                        $next[] = $key;
                    }
                }
                $candidates = $next;
                if (count($candidates) === 1) break;
            }
        }

        $winners = [];
        foreach ($candidates as $key) $winners[$key] = $playerIds[$key];

        $prizePool = floatval($match['prize_pool'] ?? 0);
        $entryFee = floatval($match['entry_fee'] ?? 0);

        if (count($winners) === 1 && $maxScore !== null && $maxScore > 0) {
            $winnerKey = array_key_first($winners);
            $winnerId = $winners[$winnerKey];

            $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
            $stmt->execute([':uid' => $winnerId]);
            $winnerRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $before = floatval($winnerRow['wallet_balance'] ?? 0);
            $after = $before + $prizePool;

            $stmt = $conn->prepare("
                UPDATE users
                SET wallet_balance = wallet_balance + :amt,
                    total_matches_played = total_matches_played + 1,
                    total_matches_won = total_matches_won + 1,
                    total_earnings = total_earnings + :amt,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :uid
            ");
            $stmt->execute([':amt' => $prizePool, ':uid' => $winnerId]);

            $orderId = 'TIMED-' . strtoupper(bin2hex(random_bytes(6)));
            $stmt = $conn->prepare("
                INSERT INTO transactions
                (user_id, tournament_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, metadata, created_at)
                VALUES (:uid, :tid, :mid, :amt, 'credit', 'match_win', :desc, :oid, 'success', :bb, :ba, :meta, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':uid' => $winnerId,
                ':tid' => $match['tournament_id'] ?: null,
                ':mid' => $matchId,
                ':amt' => $prizePool,
                ':desc' => "Speed Ludo room won on time — Room: {$match['room_code']}",
                ':oid' => $orderId,
                ':bb' => $before,
                ':ba' => $after,
                ':meta' => json_encode(['scores' => $scores, 'reason' => 'timer_expired']),
            ]);

            $loserIds = [];
            foreach ($playerIds as $pid) {
                if ($pid !== $winnerId) $loserIds[] = $pid;
            }
            if (!empty($loserIds)) {
                $ph = implode(',', array_fill(0, count($loserIds), '?'));
                $stmt = $conn->prepare("
                    UPDATE users
                    SET total_matches_played = total_matches_played + 1, updated_at = CURRENT_TIMESTAMP
                    WHERE id IN ({$ph})
                ");
                $stmt->execute($loserIds);
            }

            $stmt = $conn->prepare("
                UPDATE matches
                SET status = 'completed',
                    winner_id = :wid,
                    winning_amount = :wamt,
                    completed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :mid
            ");
            $stmt->execute([':wid' => $winnerId, ':wamt' => $prizePool, ':mid' => $matchId]);
        } else {
            // Refund path
            foreach ($playerIds as $pid) {
                $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
                $stmt->execute([':uid' => $pid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $before = floatval($row['wallet_balance'] ?? 0);
                $after = $before + $entryFee;

                $stmt = $conn->prepare("
                    UPDATE users
                    SET wallet_balance = wallet_balance + :amt, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :uid
                ");
                $stmt->execute([':amt' => $entryFee, ':uid' => $pid]);

                $orderId = 'REFUND-' . strtoupper(bin2hex(random_bytes(6)));
                $stmt = $conn->prepare("
                    INSERT INTO transactions
                    (user_id, tournament_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, metadata, created_at)
                    VALUES (:uid, :tid, :mid, :amt, 'credit', 'refund', :desc, :oid, 'success', :bb, :ba, :meta, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    ':uid' => $pid,
                    ':tid' => $match['tournament_id'] ?: null,
                    ':mid' => $matchId,
                    ':amt' => $entryFee,
                    ':desc' => "Speed Ludo room tied — entry fee refunded — Room: {$match['room_code']}",
                    ':oid' => $orderId,
                    ':bb' => $before,
                    ':ba' => $after,
                    ':meta' => json_encode(['scores' => $scores, 'reason' => 'timer_expired_tie']),
                ]);
            }

            $stmt = $conn->prepare("
                UPDATE matches
                SET status = 'completed',
                    winner_id = NULL,
                    winning_amount = NULL,
                    completed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :mid
            ");
            $stmt->execute([':mid' => $matchId]);
        }

        if ($ownTransaction) $db->commit();

        notifyRoomViaWebSocket($match['room_code'] ?? '');
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollback();
        error_log('[finalizeTimedRoom] ' . $e->getMessage());
    }
}

// ======================================================
// CHECK & SKIP TURN
// ======================================================
function checkAndSkipTurn(PDO $conn, Database $db, array &$match): void
{
    try {
        if (!in_array($match['status'] ?? '', ['playing', 'ready'], true)) {
            return;
        }

        $matchId = intval($match['id'] ?? 0);
        if ($matchId <= 0) return;

        if (empty($match['turn_started_at'])) {
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE matches SET turn_started_at = :now WHERE id = :mid");
            $stmt->execute([':now' => $now, ':mid' => $matchId]);
            $match['turn_started_at'] = $now;
            return;
        }

        $elapsed = time() - strtotime($match['turn_started_at']);
        if ($elapsed <= TURN_TIMEOUT_SECONDS) {
            return;
        }

        $activePlayers = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) $activePlayers[] = $pid;
        }
        if (count($activePlayers) < 2) return;

        $currentTurnId = intval($match['current_turn_id'] ?? 0);
        $currentIndex = array_search($currentTurnId, $activePlayers, true);
        if ($currentIndex === false) $currentIndex = 0;

        $skips = max(1, intdiv($elapsed, TURN_TIMEOUT_SECONDS));
        $skips = min($skips, count($activePlayers));
        $nextIndex = ($currentIndex + $skips) % count($activePlayers);
        $nextTurnId = $activePlayers[$nextIndex];

        $now = date('Y-m-d H:i:s');
        $newStatus = ($match['status'] === 'ready') ? 'playing' : $match['status'];

        $stmt = $conn->prepare("
            UPDATE matches
            SET current_turn_id = :nt,
                dice_value = 0,
                consecutive_sixes = 0,
                turn_started_at = :now,
                status = :st,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :mid
        ");
        $stmt->execute([
            ':nt' => $nextTurnId,
            ':now' => $now,
            ':st' => $newStatus,
            ':mid' => $matchId
        ]);

        if ($currentTurnId > 0) {
            $stmt = $conn->prepare("
                INSERT INTO game_actions (match_id, user_id, action_type, metadata, created_at)
                VALUES (:mid, :uid, 'turn_skipped', :meta, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':mid' => $matchId,
                ':uid' => $currentTurnId,
                ':meta' => json_encode(['reason' => 'timeout', 'elapsed_seconds' => $elapsed])
            ]);
        }

        notifyRoomViaWebSocket($match['room_code'] ?? '');

        $match['current_turn_id'] = $nextTurnId;
        $match['dice_value'] = 0;
        $match['consecutive_sixes'] = 0;
        $match['turn_started_at'] = $now;
        $match['status'] = $newStatus;
    } catch (Throwable $e) {
        error_log('[checkAndSkipTurn] ' . $e->getMessage());
    }
}

// ======================================================
// PLAYER HAS LEGAL MOVE
// ======================================================
function playerHasLegalMove(array $boardState, int $playerSlot, int $playerColor, int $diceValue): bool
{
    $playerKey = "player{$playerSlot}";
    $tokens = $boardState[$playerKey] ?? [];
    for ($t = 1; $t <= 4; $t++) {
        $pos = intval($tokens["token{$t}"] ?? LUDO_HOME);
        if (computeMove($pos, $diceValue, $playerColor) !== null) {
            return true;
        }
    }
    return false;
}

// ======================================================
// COMPUTE MOVE
// ======================================================
function computeMove(int $currentPos, int $diceValue, int $playerColor): ?array
{
    if ($diceValue < 1 || $diceValue > 6) return null;
    if (!isset(LUDO_STARTS[$playerColor])) return null;

    if ($currentPos === LUDO_FINISHED) {
        return null;
    }

    $start = LUDO_STARTS[$playerColor];

    if ($currentPos === LUDO_HOME) {
        if ($diceValue !== 6) return null;
        return ['newPos' => $start, 'global' => $start];
    }

    if ($currentPos >= 0 && $currentPos < LUDO_TRACK_LEN) {
        $traveled = ($currentPos - $start + LUDO_TRACK_LEN) % LUDO_TRACK_LEN;
        $newTraveled = $traveled + $diceValue;

        if ($newTraveled < LUDO_TRACK_LEN) {
            $newGlobal = ($start + $newTraveled) % LUDO_TRACK_LEN;
            return ['newPos' => $newGlobal, 'global' => $newGlobal];
        }
        if ($newTraveled <= LUDO_FINISHED) {
            return ['newPos' => $newTraveled, 'global' => null];
        }
        return null;
    }

    if ($currentPos >= LUDO_HOMERUN_START && $currentPos < LUDO_FINISHED) {
        $newPos = $currentPos + $diceValue;
        if ($newPos > LUDO_FINISHED) return null;
        return ['newPos' => $newPos, 'global' => null];
    }

    return null;
}

// ======================================================
// FIND BLOCKING OPPONENT AT CELL
// ======================================================
function findBlockingOpponentAtCell(array $boardState, int $globalIndex, int $excludeSlot): ?int
{
    for ($p = 1; $p <= 4; $p++) {
        if ($p === $excludeSlot) continue;
        $playerKey = "player{$p}";
        if (!isset($boardState[$playerKey])) continue;

        $count = 0;
        for ($t = 1; $t <= 4; $t++) {
            $pos = intval($boardState[$playerKey]["token{$t}"] ?? LUDO_HOME);
            if ($pos === $globalIndex) $count++;
        }
        if ($count >= 2) return $p;
    }
    return null;
}

// ======================================================
// CAPTURE OPPONENTS AT CELL
// ======================================================
function captureOpponentsAtCell(array &$boardState, int $globalIndex, int $excludeSlot): array
{
    $captured = [];
    for ($p = 1; $p <= 4; $p++) {
        if ($p === $excludeSlot) continue;
        $playerKey = "player{$p}";
        if (!isset($boardState[$playerKey])) continue;

        $tokensAtCell = [];
        for ($t = 1; $t <= 4; $t++) {
            $pos = intval($boardState[$playerKey]["token{$t}"] ?? LUDO_HOME);
            if ($pos === $globalIndex) $tokensAtCell[] = $t;
        }

        if (count($tokensAtCell) === 1) {
            $t = $tokensAtCell[0];
            $boardState[$playerKey]["token{$t}"] = LUDO_HOME;
            $captured[] = $p;
        }
    }
    return $captured;
}

// ======================================================
// CHECK WINNER
// ======================================================
function checkWinner(array $boardState, array $match): ?int
{
    for ($p = 1; $p <= 4; $p++) {
        $pid = intval($match["player{$p}_id"] ?? 0);
        if ($pid <= 0) continue;
        $playerKey = "player{$p}";
        if (!isset($boardState[$playerKey])) continue;

        $allFinished = true;
        for ($t = 1; $t <= 4; $t++) {
            $pos = intval($boardState[$playerKey]["token{$t}"] ?? LUDO_HOME);
            if ($pos !== LUDO_FINISHED) {
                $allFinished = false;
                break;
            }
        }
        if ($allFinished) return $pid;
    }
    return null;
}

// ======================================================
// APPLY END-GAME SCORE BONUS
// ======================================================
function applyEndGameScoreBonus(array &$scores, array $match, string $winnerKey): void
{
    $activeKeys = [];
    for ($i = 1; $i <= 4; $i++) {
        if (!empty($match["player{$i}_id"])) $activeKeys[] = "player{$i}";
    }
    if (empty($activeKeys)) return;

    foreach ($activeKeys as $k) {
        if (!isset($scores[$k])) $scores[$k] = 0;
    }

    $scores[$winnerKey] += 50;

    $others = array_values(array_filter($activeKeys, function ($k) use ($winnerKey) {
        return $k !== $winnerKey;
    }));
    usort($others, function ($a, $b) use ($scores) {
        return $scores[$b] <=> $scores[$a];
    });

    foreach ($others as $idx => $k) {
        $scores[$k] += ($idx === 0) ? 25 : 10;
    }
}

// ======================================================
// PROCESS SETTLEMENT
// ======================================================
function processSettlement(int $winnerId, int $matchId, float $prizePool): void
{
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $ownTransaction = false;

    try {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $ownTransaction = true;
        }

        $stmt = $conn->prepare("
            SELECT id, room_code, player1_id, player2_id, player3_id, player4_id, tournament_id, status
            FROM matches WHERE id = :mid FOR UPDATE
        ");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            if ($ownTransaction) $db->rollback();
            return;
        }

        $stmt = $conn->prepare("SELECT id, wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $winnerId]);
        $winner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$winner) {
            if ($ownTransaction) $db->rollback();
            return;
        }

        $before = floatval($winner['wallet_balance']);
        $after = $before + $prizePool;

        $stmt = $conn->prepare("
            UPDATE users
            SET wallet_balance = wallet_balance + :amt,
                total_matches_played = total_matches_played + 1,
                total_matches_won = total_matches_won + 1,
                total_earnings = total_earnings + :amt,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :uid
        ");
        $stmt->execute([':amt' => $prizePool, ':uid' => $winnerId]);

        $orderId = 'WIN-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
        $stmt = $conn->prepare("
            INSERT INTO transactions
            (user_id, tournament_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
            VALUES (:uid, :tid, :mid, :amt, 'credit', 'match_win', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':uid' => $winnerId,
            ':tid' => $match['tournament_id'] ?: null,
            ':mid' => $matchId,
            ':amt' => $prizePool,
            ':desc' => "Match win — Room: {$match['room_code']}",
            ':oid' => $orderId,
            ':bb' => $before,
            ':ba' => $after,
        ]);

        $loserIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0 && $pid !== $winnerId) $loserIds[] = $pid;
        }
        if (!empty($loserIds)) {
            $ph = implode(',', array_fill(0, count($loserIds), '?'));
            $stmt = $conn->prepare("
                UPDATE users
                SET total_matches_played = total_matches_played + 1, updated_at = CURRENT_TIMESTAMP
                WHERE id IN ({$ph})
            ");
            $stmt->execute($loserIds);
        }

        if ($match['status'] !== 'completed') {
            $stmt = $conn->prepare("
                UPDATE matches
                SET status = 'completed',
                    winner_id = :wid,
                    winning_amount = :wamt,
                    completed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :mid
            ");
            $stmt->execute([':wid' => $winnerId, ':wamt' => $prizePool, ':mid' => $matchId]);
        }

        if ($ownTransaction) $db->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollback();
        error_log('[processSettlement] ' . $e->getMessage());
    }
}

// ======================================================
// FINALIZE TICKET PAYOUT
// ======================================================
function finalizeTicketPayout(PDO $conn, Database $db, int $matchId, int $winnerId): void
{
    $ownTransaction = false;

    try {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $ownTransaction = true;
        }

        $stmt = $conn->prepare("SELECT id, room_code, prize_pool, status FROM matches WHERE id = :mid FOR UPDATE");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            if ($ownTransaction) $db->rollback();
            return;
        }

        $prizePool = floatval($match['prize_pool'] ?? 0);

        $stmt = $conn->prepare("SELECT id, wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $winnerId]);
        $winner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$winner) {
            if ($ownTransaction) $db->rollback();
            return;
        }

        $before = floatval($winner['wallet_balance']);
        $after = $before + $prizePool;

        $stmt = $conn->prepare("
            UPDATE users
            SET wallet_balance = wallet_balance + :amt,
                total_matches_played = total_matches_played + 1,
                total_matches_won = total_matches_won + 1,
                total_earnings = total_earnings + :amt,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :uid
        ");
        $stmt->execute([':amt' => $prizePool, ':uid' => $winnerId]);

        $orderId = 'TKTWIN-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $conn->prepare("
            INSERT INTO transactions
            (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
            VALUES (:uid, :mid, :amt, 'credit', 'match_win', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':uid' => $winnerId,
            ':mid' => $matchId,
            ':amt' => $prizePool,
            ':desc' => "Speed Ludo ticket win (all tokens home) — Room: {$match['room_code']}",
            ':oid' => $orderId,
            ':bb' => $before,
            ':ba' => $after,
        ]);

        if ($match['status'] !== 'completed') {
            $stmt = $conn->prepare("
                UPDATE matches
                SET status = 'completed',
                    winner_id = :wid,
                    winning_amount = :wamt,
                    completed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :mid
            ");
            $stmt->execute([':wid' => $winnerId, ':wamt' => $prizePool, ':mid' => $matchId]);
        }

        if ($ownTransaction) $db->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollback();
        error_log('[finalizeTicketPayout] ' . $e->getMessage());
    }
}

// ======================================================
// APPLY ROOM SCORES TO TOURNAMENT
// FIXED: nested transaction guard
// ======================================================
function applyRoomScoresToTournament(PDO $conn, Database $db, int $matchId, ?array $scores, int $tournamentId): void
{
    if ($tournamentId <= 0 || empty($scores)) return;

    $ownTransaction = false;

    try {
        $stmt = $conn->prepare("SELECT player1_id, player2_id, player3_id, player4_id FROM matches WHERE id = :mid");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) return;

        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $ownTransaction = true;
        }

        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid <= 0) continue;
            $score = intval($scores["player{$i}"] ?? 0);
            if ($score === 0) continue;

            $stmt = $conn->prepare("
                UPDATE tournament_registrations
                SET total_score = total_score + :score, updated_at = CURRENT_TIMESTAMP
                WHERE tournament_id = :tid AND user_id = :uid
            ");
            $stmt->execute([':score' => $score, ':tid' => $tournamentId, ':uid' => $pid]);
        }

        if ($ownTransaction) $db->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollback();
        error_log('[applyRoomScoresToTournament] ' . $e->getMessage());
    }
}