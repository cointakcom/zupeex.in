<?php
/**
 * ======================================================
 * JOIN.PHP - Join via Invite Link (CSRF FIXED)
 * Zupeex - Invite Join Page
 * Version: 2.2.0 - CSRF AUTO-SYNC INTEGRATION
 * ======================================================
 */

if (!defined('BASE_PATH')) { define('BASE_PATH', __DIR__); }
require_once __DIR__ . '/config/db.php';

$roomCode = isset($_GET['room']) ? trim($_GET['room']) : '';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/' || $basePath == '') $basePath = '';

// 🔥 CSRF Token - CSRFToken class se
$csrf_token = CSRFToken::generate();

$isLoggedIn = isLoggedIn();
$userId = $isLoggedIn ? getCurrentUserId() : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title>Join Game - Zupeex</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Slab:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Roboto Slab', serif; background: #FFFCF8; color: #000000; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .join-container { max-width: 480px; width: 100%; text-align: center; }
        .join-card { background: #FFFFFF; border-radius: 16px; padding: 32px; border: 1px solid rgba(125,2,171,0.1); box-shadow: 0 4px 15px rgba(125,2,171,0.15); margin-top: 20px; }
        .join-card .icon { font-size: 64px; display: block; margin-bottom: 16px; }
        .join-card h1 { font-size: 24px; font-weight: 800; color: #7D02AB; margin-bottom: 8px; }
        .join-card p { color: #555555; margin-bottom: 20px; line-height: 1.6; font-weight: 700; }
        .join-card .room-code-display {
            background: #F5E6FF; border: 1px solid rgba(125,2,171,0.2);
            border-radius: 10px; padding: 12px; font-size: 28px; font-weight: 800;
            color: #7D02AB; letter-spacing: 3px; margin-bottom: 20px;
        }
        .join-btn {
            padding: 14px 32px; border: none; border-radius: 10px;
            background: #7D02AB;
            color: #FFFFFF; font-weight: 800; font-size: 16px; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s; font-family: 'Roboto Slab', serif; width: 100%;
        }
        .join-btn:hover { transform: scale(1.02); box-shadow: 0 0 30px rgba(125,2,171,0.3); }
        .join-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        .error-message { color: #B91C1C; background: rgba(239,68,68,0.1); padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; font-weight: 700; }
        .success-message { color: #047857; background: rgba(16,185,129,0.1); padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; font-weight: 700; }
        .back-link { display: inline-block; margin-top: 16px; color: #7D02AB; text-decoration: none; font-size: 14px; font-weight: 800; }
        .back-link:hover { color: #6A0292; }
    </style>
</head>
<body>
    <div class="join-container">
        <div class="join-card">
            <span class="icon">🎲</span>
            <h1>Join Game</h1>
            <p>You have been invited to play Ludo!</p>
            <div class="room-code-display"><?php echo htmlspecialchars($roomCode ?: '------'); ?></div>
            <div id="message"></div>
            <?php echo CSRFToken::getHTMLField(); ?>
            <button class="join-btn" id="joinBtn" onclick="handleJoin()">🎯 Join Game</button>
            <a href="index.php" class="back-link">← Back to Home</a>
        </div>
    </div>
    
    <!-- 🔥 AuthHelper for CSRF -->
    <script src="<?php echo htmlspecialchars($basePath); ?>/assets/js/auth-helper.js"></script>
    <script>
        const roomCode = '<?php echo htmlspecialchars($roomCode, ENT_QUOTES); ?>';
        const userId = <?php echo $userId ? $userId : 'null'; ?>;
        const BASE_PATH = '<?php echo $basePath; ?>';
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';

        // 🔥 CSRF Token getter
        async function getCsrfToken() {
            if (window.AuthHelper && typeof window.AuthHelper.getCsrfToken === 'function') {
                return await window.AuthHelper.getCsrfToken();
            }
            return document.querySelector('input[name="csrf_token"]')?.value || CSRF_TOKEN;
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (!userId) {
                document.getElementById('message').innerHTML = `<div class="error-message">⚠️ Please <a href="index.php" style="color:#7D02AB;font-weight:800;text-decoration:underline;">login</a> to join this game.</div>`;
                document.getElementById('joinBtn').disabled = true;
                document.getElementById('joinBtn').textContent = '🔒 Login Required';
                return;
            }
            checkRoom();
        });

        function checkRoom() {
            if (!roomCode) {
                document.getElementById('message').innerHTML = `<div class="error-message">⚠️ Invalid invite link.</div>`;
                document.getElementById('joinBtn').disabled = true;
                return;
            }
            document.getElementById('joinBtn').textContent = '⏳ Checking room...';
            document.getElementById('joinBtn').disabled = true;

            fetch(BASE_PATH + `/api/invite.php?action=check_room&room=${encodeURIComponent(roomCode)}`, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const room = data.data.room;
                        if (room.is_full) {
                            document.getElementById('message').innerHTML = `<div class="error-message">❌ This room is full.</div>`;
                            document.getElementById('joinBtn').disabled = true;
                            document.getElementById('joinBtn').textContent = '🚫 Room Full';
                        } else {
                            document.getElementById('message').innerHTML = `<div class="success-message">✅ Room is available! Entry: ₹${room.entry_fee}. Click Join to enter.</div>`;
                            document.getElementById('joinBtn').disabled = false;
                            document.getElementById('joinBtn').textContent = '🎯 Join Game';
                        }
                    } else {
                        document.getElementById('message').innerHTML = `<div class="error-message">❌ ${data.message || 'Room not found'}</div>`;
                        document.getElementById('joinBtn').disabled = true;
                        document.getElementById('joinBtn').textContent = '🚫 Room Not Found';
                    }
                })
                .catch(() => {
                    document.getElementById('message').innerHTML = `<div class="error-message">❌ Network error. Please try again.</div>`;
                    document.getElementById('joinBtn').disabled = true;
                });
        }

        async function handleJoin() {
            const btn = document.getElementById('joinBtn');
            const originalText = btn.textContent;
            btn.textContent = '⏳ Joining...';
            btn.disabled = true;

            // 🔥 AuthHelper se fresh CSRF token lo
            const csrfToken = await getCsrfToken();

            fetch(BASE_PATH + '/api/invite.php?action=join', {
                method: 'POST',
                credentials: 'include',
                headers: { 
                    'Content-Type': 'application/json', 
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken 
                },
                body: JSON.stringify({ room_code: roomCode, csrf_token: csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                // 🔥 Naya token update karo
                if (data.data && data.data.csrf_token && window.AuthHelper) {
                    window.AuthHelper.updateCsrfToken(data.data.csrf_token);
                }
                
                if (data.success) {
                    document.getElementById('message').innerHTML = `<div class="success-message">✅ ${data.message}</div>`;
                    if (data.data.redirect_url) {
                        setTimeout(() => { window.location.href = data.data.redirect_url; }, 1500);
                    }
                } else {
                    document.getElementById('message').innerHTML = `<div class="error-message">❌ ${data.message}</div>`;
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            })
            .catch(() => {
                document.getElementById('message').innerHTML = `<div class="error-message">❌ Network error. Please try again.</div>`;
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }
    </script>
</body>
</html>