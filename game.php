<?php
/**
 * ======================================================
 * GAME.PHP - Ludo Game Page (FULLY FIXED)
 * Ludo Tournament Platform - Complete Game Interface
 * Version: 6.0.0 - ALL BUGS FIXED
 * ------------------------------------------------------
 * FIXES IN THIS VERSION:
 *   1. Player name resolution — only uses player{$i}_username
 *      (matches table mein player{$i}_name column nahi hai)
 *   2. myPlayerNumber fallback — agar user slot 3/4 mein hai
 *      to bhi page load hoga (galat exit nahi)
 *   3. PLAYERS JSON format — clean Object structure bhejta hai
 *      jo JS mein directly use ho sake, koi array_map nesting nahi
 *   4. Waiting overlay — proper transition from waiting to playing
 *      with polling + auto-reload
 *   5. $playerColors fallback — slot → colour mapping always valid
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once __DIR__ . '/config/db.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$userId = getCurrentUserId();
$matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;

if ($matchId <= 0) {
    header('Location: index.php');
    exit;
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
} catch (Exception $e) {
    die("Database connection failed. Please try again.");
}

// ======================================================
// LOAD MATCH WITH PLAYER USERNAMES
// ======================================================
$stmt = $conn->prepare("
    SELECT m.*,
           t.name as tournament_name,
           u1.username as p1_username,
           u2.username as p2_username,
           u3.username as p3_username,
           u4.username as p4_username
    FROM matches m
    LEFT JOIN tournaments t ON m.tournament_id = t.id
    LEFT JOIN users u1 ON m.player1_id = u1.id
    LEFT JOIN users u2 ON m.player2_id = u2.id
    LEFT JOIN users u3 ON m.player3_id = u3.id
    LEFT JOIN users u4 ON m.player4_id = u4.id
    WHERE m.id = :match_id
");
$stmt->execute([':match_id' => $matchId]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$match) {
    header('Location: index.php');
    exit;
}

// ======================================================
// ACTIVE PLAYERS
// ======================================================
$exitedPlayerIds = json_decode($match['exited_players'] ?? '', true) ?: [];

$activePlayers = [];
for ($i = 1; $i <= 4; $i++) {
    $pid = intval($match["player{$i}_id"] ?? 0);
    if ($pid > 0 && !in_array($pid, $exitedPlayerIds, true)) {
        // FIXED: Only use player{$i}_username — player{$i}_name column DB mein nahi hai
        $username = $match["p{$i}_username"] ?? "Player {$i}";
        $activePlayers[$i] = [
            'id' => $pid,
            'name' => $username,
        ];
    }
}

// ======================================================
// FIND MY PLAYER NUMBER
// FIXED: Fallback to slot with matching ID, not just exact match
// ======================================================
$myPlayerNumber = null;
foreach ($activePlayers as $num => $info) {
    if ($info['id'] === $userId) {
        $myPlayerNumber = $num;
        break;
    }
}

// If not found in active players, check if user is in the match at all (exited player)
if ($myPlayerNumber === null) {
    for ($i = 1; $i <= 4; $i++) {
        if (intval($match["player{$i}_id"] ?? 0) === $userId) {
            // User is in the match but exited — send back to dashboard
            header('Location: index.php');
            exit;
        }
    }
    // User is not in this match at all
    header('Location: index.php');
    exit;
}

// ======================================================
// PLAYER COLORS (Slot → Colour mapping)
// ======================================================
$playerColors = json_decode($match['player_colors'] ?? '', true);
if (!$playerColors || !is_array($playerColors)) {
    $playerColors = allocateColors($match['game_mode'] ?? '1vs1');
}

// Ensure every active slot has a colour
foreach (array_keys($activePlayers) as $slot) {
    if (!isset($playerColors[$slot]) || !is_numeric($playerColors[$slot])) {
        $playerColors[$slot] = $slot;
    }
    $playerColors[$slot] = intval($playerColors[$slot]);
}

// ======================================================
// CSRF TOKEN
// ======================================================
$csrf_token = CSRFToken::generate();

// ======================================================
// BASE PATH
// ======================================================
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/' || $basePath == '') {
    $basePath = '';
}

// ======================================================
// MATCH STATE
// ======================================================
$isGameOver = ($match['status'] === 'completed');
$winnerId = intval($match['winner_id'] ?? 0);
$matchStatus = $match['status'];
$isTimedRoom = ($match['scores'] !== null);
$matchEndsAt = $match['match_ends_at'];
$isWaiting = ($matchStatus === 'waiting');
$neededPlayers = ($match['game_mode'] === '1vs1') ? 2 : 4;
$seatedCount = count($activePlayers);

// ======================================================
// BUILD PLAYERS MAP FOR JS
// FIXED: Clean structure — { "player1": {id, name, color, is_me}, ... }
// ======================================================
$playersMap = [];
foreach ($activePlayers as $num => $info) {
    $playersMap['player' . $num] = [
        'id' => intval($info['id']),
        'name' => $info['name'],
        'color' => intval($playerColors[$num] ?? $num),
        'is_me' => ($info['id'] === $userId),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#7D02AB">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title>Ludo Game - Zupeex</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Slab:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Roboto Slab', serif;
            background: #7D02AB;
            overflow: hidden;
            height: 100vh;
            height: 100dvh;
        }

        .game-wrapper {
            max-width: 480px;
            margin: 0 auto;
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            background: #FFFCF8;
            position: relative;
        }

        .game-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            background: #7D02AB;
            flex-shrink: 0;
            z-index: 10;
            box-shadow: 0 4px 15px rgba(125,2,171,0.15);
            gap: 8px;
        }

        .game-header .back-btn {
            color: #FFFFFF;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            padding: 6px 14px;
            background: rgba(255,255,255,0.15);
            border-radius: 20px;
            transition: background 0.2s;
            border: none;
            cursor: pointer;
            font-family: 'Roboto Slab', serif;
        }

        .game-header .back-btn:hover { background: rgba(255,255,255,0.25); }

        .game-header .room-code {
            font-size: 16px;
            font-weight: 800;
            color: #FFFFFF;
            letter-spacing: 1px;
        }

        .game-header .player-info {
            font-size: 11px;
            color: rgba(255,255,255,0.8);
            text-align: right;
            font-weight: 700;
            max-width: 130px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .game-header .player-info span { color: #FFFFFF; font-weight: 800; }

        .player-bars {
            display: flex;
            justify-content: space-between;
            padding: 8px 16px;
            gap: 12px;
            background: #FFFFFF;
        }

        .player-bar {
            flex: 1;
            background: #FFFFFF;
            border-radius: 12px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 2px solid rgba(125,2,171,0.15);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: border-color 0.3s;
        }

        .player-bar.active-turn {
            border-color: #7D02AB;
            background: #F5E6FF;
            animation: pulse-border 1.5s ease-in-out infinite;
        }

        @keyframes pulse-border {
            0%, 100% { border-color: #7D02AB; box-shadow: 0 0 10px rgba(125,2,171,0.2); }
            50% { border-color: #9B30C4; box-shadow: 0 0 20px rgba(125,2,171,0.4); }
        }

        .player-bar .avatar-mini {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 16px; flex-shrink: 0;
            color: #FFFFFF;
        }

        .player-bar .bar-info { flex: 1; min-width: 0; }

        .player-bar .bar-name {
            font-size: 13px; font-weight: 800; color: #000000;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .player-bar .bar-tokens { font-size: 10px; color: #555555; font-weight: 700; }
        .player-bar .bar-score { font-size: 14px; font-weight: 800; color: #7D02AB; }

        .game-canvas-container {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            padding: 8px; overflow: hidden; position: relative;
            background: #FFFCF8;
        }

        .game-canvas-container #ludoCanvas {
            max-width: 100%; max-height: 100%;
            border-radius: 16px; background: #FFFCF8;
            box-shadow: 0 4px 15px rgba(125,2,171,0.15);
            cursor: pointer; touch-action: none;
        }

        .dice-display {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 70px; height: 70px;
            background: #FFFFFF; border-radius: 16px;
            display: none; align-items: center; justify-content: center;
            font-size: 36px; font-weight: 900; color: #7D02AB;
            box-shadow: 0 8px 32px rgba(125,2,171,0.2);
            z-index: 20; pointer-events: none;
        }

        .dice-display.rolling { animation: diceRoll 0.6s ease; }

        @keyframes diceRoll {
            0% { transform: translate(-50%, -50%) rotate(0deg) scale(0.5); opacity: 0; }
            50% { transform: translate(-50%, -50%) rotate(360deg) scale(1.2); opacity: 1; }
            100% { transform: translate(-50%, -50%) rotate(720deg) scale(1); opacity: 1; }
        }

        .game-footer {
            padding: 12px 16px;
            background: #FFFFFF;
            border-top: 1px solid rgba(125,2,171,0.1);
            flex-shrink: 0;
            display: flex; justify-content: space-between; align-items: center;
            gap: 8px; z-index: 10;
            box-shadow: 0 -2px 12px rgba(125,2,171,0.1);
        }

        .game-footer .turn-text { font-size: 12px; color: #555555; font-weight: 700; }
        .game-footer .turn-text .highlight { color: #7D02AB; font-weight: 800; }

        .btn-roll {
            padding: 14px 32px;
            background: #7D02AB;
            color: #FFFFFF; border: none; border-radius: 30px;
            font-weight: 800; font-size: 16px; cursor: pointer;
            font-family: 'Roboto Slab', serif;
            box-shadow: 0 4px 16px rgba(125,2,171,0.3);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-roll:hover { transform: scale(1.05); }
        .btn-roll:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }

        .timer-circle {
            width: 48px; height: 48px;
            border-radius: 50%;
            border: 3px solid rgba(125,2,171,0.2);
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }

        .timer-circle .timer-text { font-size: 16px; font-weight: 800; color: #000000; z-index: 1; }
        .timer-circle.warning { border-color: #B45309; }
        .timer-circle.warning .timer-text { color: #B45309; }
        .timer-circle.danger { border-color: #B91C1C; animation: pulse 0.5s infinite; }
        .timer-circle.danger .timer-text { color: #B91C1C; }
        .speed-timer { background: #7D02AB; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 800; color: #FFFFFF; }
        .speed-timer.danger { background: #B91C1C; animation: pulse 0.6s infinite; }

        @keyframes pulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.1)} }

        .winner-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255,252,248,0.95); backdrop-filter: blur(10px);
            display: none; flex-direction: column;
            align-items: center; justify-content: center; z-index: 30;
        }

        .winner-overlay.active { display: flex; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }

        .winner-overlay .trophy { font-size: 72px; animation: bounce 1s infinite; }
        @keyframes bounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-20px)} }

        .winner-overlay .win-title { font-size: 28px; font-weight: 900; color: #7D02AB; margin-top: 12px; }
        .winner-overlay .win-amount { font-size: 16px; color: #555555; margin-top: 4px; font-weight: 700; }
        .winner-overlay .win-amount span { color: #7D02AB; font-weight: 800; }

        .winner-overlay .btn-back {
            margin-top: 20px; padding: 12px 32px;
            background: #7D02AB; color: #FFFFFF;
            border: none; border-radius: 30px;
            font-weight: 800; font-size: 16px; cursor: pointer;
            font-family: 'Roboto Slab', serif;
        }

        .waiting-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: #FFFCF8;
            display: none; flex-direction: column;
            align-items: center; justify-content: center; z-index: 25;
        }
        .waiting-overlay.active { display: flex; }
        .waiting-spinner {
            width: 60px; height: 60px;
            border: 4px solid rgba(125,2,171,0.1);
            border-top-color: #7D02AB;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .waiting-text { font-size: 18px; font-weight: 800; color: #000000; margin-top: 16px; }
        .waiting-sub { font-size: 13px; color: #555555; margin-top: 4px; font-weight: 700; }

        .toast-zupee {
            position: fixed; bottom: 100px; left: 50%;
            transform: translateX(-50%) translateY(20px);
            padding: 12px 24px; border-radius: 30px;
            font-weight: 800; font-size: 14px; z-index: 2000;
            opacity: 0; transition: all 0.3s ease;
            pointer-events: none; white-space: nowrap;
        }

        .toast-zupee.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast-zupee.success { background: #D1FAE5; color: #047857; }
        .toast-zupee.error { background: #FEE2E2; color: #B91C1C; }
        .toast-zupee.info { background: #E0E7FF; color: #3730A3; }
        .toast-zupee.warning { background: #FEF3C7; color: #B45309; }

        @media (max-width: 480px) {
            .game-header { padding: 8px 12px; }
            .game-footer { padding: 8px 12px; }
            .btn-roll { padding: 10px 24px; font-size: 14px; }
            .timer-circle { width: 40px; height: 40px; }
            .timer-circle .timer-text { font-size: 14px; }
            .dice-display { width: 56px; height: 56px; font-size: 28px; }
        }
    </style>
</head>
<body>
    <div class="game-wrapper">

        <!-- ==================== HEADER ==================== -->
        <div class="game-header">
            <button class="back-btn" id="exitBtn">🚪 Exit</button>
            <div class="room-code">🔑 <?php echo htmlspecialchars($match['room_code']); ?></div>
            <div class="player-info" id="playerInfoText">
                <?php
                echo htmlspecialchars($activePlayers[$myPlayerNumber]['name'] ?? 'You');
                echo ' <span>vs</span> ';
                $others = [];
                foreach ($activePlayers as $num => $info) {
                    if ($num !== $myPlayerNumber) {
                        $others[] = htmlspecialchars($info['name']);
                    }
                }
                echo implode(', ', $others);
                ?>
            </div>
            <?php if ($isTimedRoom): ?>
            <div class="speed-timer" id="speedTimer" title="Speed Ludo — time remaining">
                ⏱️ <span id="speedTimerText">--:--</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- ==================== PLAYER BARS ==================== -->
        <div class="player-bars" id="playerBars">
            <?php foreach ($activePlayers as $num => $info):
                $colorNum = intval($playerColors[$num] ?? $num);
                // Colour names based on LUDO_STARTS alignment (server-side)
                // 1=Green (Top-Right), 2=Red (Top-Left), 3=Blue (Bottom-Left), 4=Yellow (Bottom-Right)
                $colorNames = [1 => 'Green', 2 => 'Red', 3 => 'Blue', 4 => 'Yellow'];
                $colorHex = [1 => '#0B6E2C', 2 => '#E63329', 3 => '#1E9FE0', 4 => '#F5C518'];
                $avatarBg = $colorHex[$colorNum] ?? '#7D02AB';
            ?>
            <div class="player-bar" id="player<?php echo $num; ?>Bar" data-player="<?php echo $num; ?>">
                <div class="avatar-mini" style="background: <?php echo $avatarBg; ?>; color: #FFFFFF;"
                     title="<?php echo htmlspecialchars($colorNames[$colorNum] ?? ''); ?>">
                    <?php echo strtoupper(substr($info['name'], 0, 1)); ?>
                </div>
                <div class="bar-info">
                    <div class="bar-name">
                        <?php echo htmlspecialchars($info['name']); ?>
                        <?php echo ($num === $myPlayerNumber) ? ' (You)' : ''; ?>
                    </div>
                    <div class="bar-tokens">🏠 <span id="p<?php echo $num; ?>Home">0</span>/4 Home</div>
                </div>
                <div class="bar-score" id="p<?php echo $num; ?>Score">0</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ==================== GAME CANVAS ==================== -->
        <div class="game-canvas-container">
            <div id="ludoCanvas"></div>
            <div class="dice-display" id="diceDisplay">1</div>

            <div class="winner-overlay <?php echo $isGameOver ? 'active' : ''; ?>" id="winnerOverlay">
                <div class="trophy">🏆</div>
                <div class="win-title" id="winnerName">
                    <?php
                    echo ($winnerId === $userId)
                        ? '🎉 You Won!'
                        : ($isTimedRoom ? '⏱️ Room Finished' : '😔 You Lost');
                    ?>
                </div>
                <div class="win-amount" id="winnerAmountWrap">
                    Prize: <span id="winnerAmount">
                        ₹<?php echo number_format(floatval($match['winning_amount'] ?? 0), 2); ?>
                    </span>
                </div>
                <?php if ($isTimedRoom): ?>
                <div class="win-amount" style="font-size:13px;opacity:0.8;margin-top:4px;">
                    Your final rank is calculated once every room in this tournament finishes —
                    check the Leaderboard on your Dashboard.
                </div>
                <?php endif; ?>
                <button class="btn-back" onclick="window.location.href='index.php'">
                    ← Back to Dashboard
                </button>
            </div>

            <div class="waiting-overlay <?php echo $isWaiting ? 'active' : ''; ?>" id="waitingOverlay">
                <div class="waiting-spinner"></div>
                <div class="waiting-text">Waiting for opponent(s)...</div>
                <div class="waiting-sub" id="waitingSubText">
                    <?php echo $seatedCount; ?>/<?php echo $neededPlayers; ?> players seated
                </div>
            </div>
        </div>

        <!-- ==================== FOOTER ==================== -->
        <div class="game-footer">
            <div class="turn-text">
                Turn: <span class="highlight" id="turnDisplay">
                    <?php echo $isWaiting ? 'Waiting...' : 'Loading...'; ?>
                </span>
            </div>
            <button class="btn-roll" id="rollBtn" disabled>
                🎲 Roll Dice
            </button>
            <div class="timer-circle" id="timerCircle">
                <span class="timer-text" id="timerText">15</span>
            </div>
        </div>

    </div>

    <div class="toast-zupee" id="toast"><span id="toastMessage"></span></div>

    <!-- ==================== SCRIPTS ==================== -->
    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
    <script src="<?php echo htmlspecialchars($basePath); ?>/assets/js/auth-helper.js"></script>
    <script src="<?php echo htmlspecialchars($basePath); ?>/assets/js/ludo-engine.js"></script>
    <script>
        // ==================== CONSTANTS ====================
        const MATCH_ID = <?php echo $matchId; ?>;
        const USER_ID = <?php echo $userId; ?>;
        const MY_PLAYER_NUMBER = <?php echo $myPlayerNumber; ?>;
        const GAME_MODE = '<?php echo htmlspecialchars($match['game_mode'] ?? '1vs1'); ?>';
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
        const BASE_PATH = '<?php echo htmlspecialchars($basePath); ?>';
        const REALTIME_URL = '<?php echo htmlspecialchars(WS_RELAY_PUBLIC_URL); ?>';
        const ROOM_CODE = '<?php echo htmlspecialchars($match['room_code']); ?>';
        const IS_TIMED_ROOM = <?php echo $isTimedRoom ? 'true' : 'false'; ?>;
        const IS_WAITING = <?php echo $isWaiting ? 'true' : 'false'; ?>;

        // FIXED: Clean players map — no nested arrays, no array_map tricks
        const PLAYERS_MAP = <?php echo json_encode($playersMap, JSON_UNESCAPED_SLASHES); ?>;

        // ==================== DOM REFS ====================
        const canvas = document.getElementById('ludoCanvas');
        let engine = null;
        let perTurnTimerInterval = null;
        let perTurnTimeLeft = 15;
        let lastTimerTurn = null;
        const PER_TURN_MAX = 15;

        // ==================== CSRF ====================
        async function getCsrfToken() {
            if (window.AuthHelper && typeof window.AuthHelper.getCsrfToken === 'function') {
                return await window.AuthHelper.getCsrfToken();
            }
            return CSRF_TOKEN;
        }

        // ==================== TOAST ====================
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const msg = document.getElementById('toastMessage');
            if (!toast || !msg) return;
            msg.textContent = message;
            toast.className = 'toast-zupee ' + type + ' show';
            clearTimeout(window._toastTimer);
            window._toastTimer = setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // ==================== PLAYER BARS UPDATE ====================
        function updatePlayerBars(state) {
            Object.keys(PLAYERS_MAP).forEach(key => {
                const num = key.replace('player', '');
                const tokens = state.board[key] || {};
                let homeCount = 0;
                for (let i = 1; i <= 4; i++) {
                    if ((tokens['token' + i] ?? -1) === 58) homeCount++;
                }
                const homeEl = document.getElementById('p' + num + 'Home');
                if (homeEl) homeEl.textContent = homeCount;

                const scoreEl = document.getElementById('p' + num + 'Score');
                if (scoreEl) {
                    if (IS_TIMED_ROOM && state.scores) {
                        scoreEl.textContent = state.scores[key] ?? 0;
                    } else {
                        scoreEl.textContent = homeCount * 25;
                    }
                }

                const barEl = document.getElementById('player' + num + 'Bar');
                if (barEl) {
                    barEl.classList.toggle('active-turn', parseInt(num, 10) === state.currentTurn);
                }
            });
        }

        // ==================== TURN DISPLAY ====================
        function updateTurnDisplay(state) {
            const turnDisplay = document.getElementById('turnDisplay');
            if (!turnDisplay) return;
            if (state.isMyTurn) {
                turnDisplay.textContent = 'Your Turn';
                turnDisplay.style.color = '#7D02AB';
            } else {
                const otherKey = 'player' + state.currentTurn;
                const otherName = (PLAYERS_MAP[otherKey] && PLAYERS_MAP[otherKey].name) || 'Opponent';
                turnDisplay.textContent = otherName + "'s Turn";
                turnDisplay.style.color = '#555555';
            }
        }

        // ==================== SPEED TIMER (Timed Room) ====================
        function updateSpeedTimer(state) {
            if (!IS_TIMED_ROOM) return;
            const el = document.getElementById('speedTimerText');
            if (!el) return;
            const secs = state.seconds_remaining;
            if (secs === null || secs === undefined) {
                el.textContent = '--:--';
                return;
            }
            const m = Math.floor(secs / 60);
            const s = secs % 60;
            el.textContent = m + ':' + String(s).padStart(2, '0');
            const wrap = document.getElementById('speedTimer');
            if (wrap) wrap.classList.toggle('danger', secs <= 60);
        }

        // ==================== PER-TURN TIMER ====================
        function startPerTurnTimer(serverSeconds) {
            stopPerTurnTimer();
            perTurnTimeLeft = (serverSeconds !== null && serverSeconds !== undefined)
                ? serverSeconds
                : PER_TURN_MAX;
            updatePerTurnTimerUI();
            perTurnTimerInterval = setInterval(() => {
                perTurnTimeLeft--;
                updatePerTurnTimerUI();
                if (perTurnTimeLeft <= 0) stopPerTurnTimer();
            }, 1000);
        }

        function stopPerTurnTimer() {
            if (perTurnTimerInterval) {
                clearInterval(perTurnTimerInterval);
                perTurnTimerInterval = null;
            }
            const circle = document.getElementById('timerCircle');
            if (circle) circle.classList.remove('warning', 'danger');
        }

        function updatePerTurnTimerUI() {
            const textEl = document.getElementById('timerText');
            const circle = document.getElementById('timerCircle');
            if (textEl) textEl.textContent = Math.max(0, perTurnTimeLeft);
            if (circle) {
                circle.classList.remove('warning', 'danger');
                if (perTurnTimeLeft <= 5) circle.classList.add('danger');
                else if (perTurnTimeLeft <= 10) circle.classList.add('warning');
            }
        }

        // ==================== DICE ANIMATION ====================
        function showDiceAnimation(value) {
            const dice = document.getElementById('diceDisplay');
            dice.textContent = value;
            dice.style.display = 'flex';
            dice.classList.add('rolling');
            setTimeout(() => {
                dice.classList.remove('rolling');
                setTimeout(() => { dice.style.display = 'none'; }, 500);
            }, 600);
        }

        // ==================== WINNER OVERLAY ====================
        function showWinner(winnerId, winningAmount) {
            const overlay = document.getElementById('winnerOverlay');
            const nameEl = document.getElementById('winnerName');
            if (!IS_TIMED_ROOM) {
                if (winnerId == USER_ID) {
                    nameEl.textContent = '🎉 You Won!';
                    nameEl.style.color = '#7D02AB';
                } else {
                    nameEl.textContent = '😔 You Lost';
                    nameEl.style.color = '#B91C1C';
                }
                const amtEl = document.getElementById('winnerAmount');
                if (amtEl && winningAmount) {
                    amtEl.textContent = '₹' + parseFloat(winningAmount).toFixed(2);
                }
            }
            overlay.classList.add('active');
        }

        // ==================== WAITING ROOM POLL ====================
        function checkWaitingRoom() {
            if (!IS_WAITING) return;
            fetch(BASE_PATH + '/api/game.php?action=get_state&match_id=' + MATCH_ID, {
                credentials: 'include'
            })
            .then(r => r.json())
            .then(d => {
                if (d.success && d.data && d.data.match && d.data.match.status !== 'waiting') {
                    window.location.reload();
                } else if (d.success && d.data && d.data.players) {
                    const seated = Object.keys(d.data.players).length;
                    const subEl = document.getElementById('waitingSubText');
                    if (subEl) {
                        subEl.textContent = seated + '/<?php echo $neededPlayers; ?> players seated';
                    }
                }
            })
            .catch(() => {});
        }

        // ==================== INIT ====================
        function init() {
            // If waiting for players, poll and return
            if (IS_WAITING) {
                setInterval(checkWaitingRoom, 3000);
                return;
            }

            // Initialize engine
            engine = new LudoEngine(canvas, {
                matchId: MATCH_ID,
                roomCode: ROOM_CODE,
                myPlayerNumber: MY_PLAYER_NUMBER,
                gameMode: GAME_MODE,
                basePath: BASE_PATH,
                csrfToken: CSRF_TOKEN,
                players: PLAYERS_MAP,
                realtimeUrl: REALTIME_URL,
            });

            // ==================== ENGINE EVENTS ====================
            engine.on('onGameStateUpdate', function(state) {
                updatePlayerBars(state);
                updateTurnDisplay(state);
                updateSpeedTimer(state);

                const rollBtn = document.getElementById('rollBtn');
                if (rollBtn) rollBtn.disabled = !state.canRoll;

                const turnActive = !state.isGameOver && ['playing', 'ready'].includes(state.status);
                if (turnActive) {
                    if (!perTurnTimerInterval || lastTimerTurn !== state.currentTurn) {
                        lastTimerTurn = state.currentTurn;
                        startPerTurnTimer(state.secondsRemainingInTurn);
                    }
                } else {
                    lastTimerTurn = null;
                    stopPerTurnTimer();
                }
            });

            engine.on('onDiceRoll', function(value) {
                showDiceAnimation(value);
                showToast('🎲 Rolled ' + value + '!', 'info');
            });

            engine.on('onCapture', function() {
                showToast('💥 Captured an opponent token!', 'success');
            });

            engine.on('onForfeit', function() {
                showToast('⚠️ Three sixes — turn forfeited!', 'warning');
            });

            engine.on('onWin', function(winnerId, winningAmount) {
                stopPerTurnTimer();
                const rollBtn = document.getElementById('rollBtn');
                if (rollBtn) rollBtn.disabled = true;
                showWinner(winnerId, winningAmount);
            });

            engine.on('onError', function(msg) {
                showToast(msg, 'error');
            });

            // ==================== ROLL BUTTON ====================
            const rollBtn = document.getElementById('rollBtn');
            if (rollBtn) {
                rollBtn.addEventListener('click', function() {
                    if (this.disabled) return;
                    this.disabled = true;
                    stopPerTurnTimer();
                    engine.rollDice().finally(() => {
                        const st = engine.getState();
                        if (!st.isGameOver) {
                            this.disabled = !st.canRoll;
                        }
                    });
                });
            }

            // ==================== EXIT BUTTON ====================
            const exitBtn = document.getElementById('exitBtn');
            if (exitBtn) {
                exitBtn.addEventListener('click', async function() {
                    if (!confirm('Exit match? You will forfeit and your opponent wins.')) {
                        return;
                    }
                    this.disabled = true;
                    const csrf = await getCsrfToken();
                    try {
                        const res = await fetch(BASE_PATH + '/api/game.php?action=exit', {
                            method: 'POST',
                            credentials: 'include',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': csrf
                            },
                            body: JSON.stringify({
                                match_id: MATCH_ID,
                                csrf_token: csrf
                            })
                        });
                        const data = await res.json();
                        if (data.success) {
                            stopPerTurnTimer();
                            const rb = document.getElementById('rollBtn');
                            if (rb) rb.disabled = true;
                            if (data.data.game_over) {
                                showWinner(data.data.winner_id, 0);
                            } else {
                                showToast('You exited the match.', 'info');
                                setTimeout(() => {
                                    window.location.href = 'index.php';
                                }, 1500);
                            }
                        } else {
                            showToast(data.message || 'Exit failed', 'error');
                            this.disabled = false;
                        }
                    } catch (e) {
                        showToast('Network error', 'error');
                        this.disabled = false;
                    }
                });
            }

            // ==================== ENGINE INIT ====================
            engine.init();

            // ==================== GAME OVER (pre-existing) ====================
            <?php if ($isGameOver): ?>
            const rb = document.getElementById('rollBtn');
            if (rb) rb.disabled = true;
            showWinner(
                <?php echo $winnerId; ?>,
                <?php echo floatval($match['winning_amount'] ?? 0); ?>
            );
            <?php endif; ?>
        }

        init();
    </script>
</body>
</html>