<?php
/**
 * ======================================================
 * ADMIN TICKETS.PHP - Tournament Tickets Management
 * Ludo Tournament Platform - 1vs1 / 1vs4 Ticket Admin
 * Version: 1.2.0 - CSRF AUTO-REFRESH FIX
 * ======================================================
 */

if (!defined('BASE_PATH')) { define('BASE_PATH', dirname(__DIR__)); }
require_once dirname(__DIR__) . '/config/db.php';
SessionManager::init();

function validateAdminSession() {
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) return false;
    try {
        $db = Database::getInstance(); $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}
if (!validateAdminSession()) { SessionManager::destroy(); header('Location: index.php'); exit; }

$csrf_token = CSRFToken::generate();
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/admin') $basePath = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Tickets - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Roboto Slab',serif;background:#FFFCF8;color:#000000;min-height:100vh}
        .admin-container{max-width:1300px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#7D02AB;border-radius:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:800;color:#FFFFFF}
        .admin-header-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .admin-header-actions span{color:#FFFFFF;font-weight:700}
        .admin-header-actions a{color:#FFFFFF;text-decoration:none;font-weight:700;font-size:13px;padding:8px 14px;border:1px solid rgba(255,255,255,0.3);border-radius:8px}
        .admin-header-actions a:hover{background:rgba(255,255,255,0.15)}
        .admin-header-actions a.logout{color:#FF6B6B;border-color:rgba(255,100,100,0.4)}
        .create-bar{background:#FFFFFF;border-radius:14px;padding:20px;margin-bottom:24px;border:1px solid rgba(125,2,171,0.1);box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .create-bar h2{font-size:16px;margin-bottom:14px;color:#7D02AB;font-weight:800}
        .create-form{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
        .create-form .fg{display:flex;flex-direction:column;gap:4px}
        .create-form label{font-size:11px;color:#555555;font-weight:700}
        .create-form select,.create-form input{padding:9px 12px;border-radius:8px;border:1px solid rgba(125,2,171,0.2);background:#FFFFFF;color:#000000;font-size:13px;font-family:'Roboto Slab',serif}
        .btn-create{padding:10px 24px;border:none;border-radius:8px;background:#7D02AB;color:#FFFFFF;font-weight:800;font-size:13px;cursor:pointer;font-family:'Roboto Slab',serif}
        .btn-create:hover{box-shadow:0 4px 15px rgba(125,2,171,0.3)}
        .mode-section{margin-bottom:32px}
        .mode-section h2{font-size:18px;margin-bottom:14px;display:flex;align-items:center;gap:10px;color:#000000;font-weight:800}
        .mode-badge{padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700}
        .mode-badge.mode-1vs1{background:rgba(59,130,246,0.15);color:#2563EB}
        .mode-badge.mode-1vs4{background:rgba(125,2,171,0.15);color:#7D02AB}
        .ticket-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
        .ticket-card{background:#FFFFFF;border-radius:12px;padding:16px;border:1px solid rgba(125,2,171,0.1);box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .ticket-card.inactive{opacity:0.5}
        .ticket-card .tc-fee{font-size:22px;font-weight:800;color:#7D02AB}
        .ticket-card .tc-row{display:flex;justify-content:space-between;font-size:12px;color:#555555;margin-top:4px;font-weight:700}
        .ticket-card .tc-waiting{margin-top:10px;padding:8px 10px;background:rgba(16,185,129,0.08);border-radius:8px;font-size:12px;color:#047857;font-weight:700}
        .ticket-card .tc-actions{display:flex;gap:8px;margin-top:12px}
        .tc-toggle{flex:1;padding:7px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Roboto Slab',serif}
        .tc-toggle.active-btn{background:rgba(239,68,68,0.15);color:#B91C1C}
        .tc-toggle.inactive-btn{background:rgba(16,185,129,0.15);color:#047857}
        .empty-msg{color:#555555;font-size:13px;padding:20px;text-align:center;font-weight:700}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:800;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{background:rgba(16,185,129,0.2);color:#047857}
        .toast.error{background:rgba(239,68,68,0.2);color:#B91C1C}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>🎟️ Tournament Tickets</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a><a href="tournaments.php">🏆 Custom Tournaments</a><a href="withdrawals.php">🏦 Withdrawals</a><a href="deposits.php">💰 Deposits</a><a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>

        <div class="create-bar">
            <h2>➕ Create New Ticket</h2>
            <div class="create-form">
                <div class="fg"><label>Mode</label><select id="newMode"><option value="1vs1">1 vs 1</option><option value="1vs4">1 vs 4</option></select></div>
                <div class="fg"><label>Entry Fee (₹)</label><input type="number" id="newFee" min="1" placeholder="100" style="width:100px"></div>
                <div class="fg"><label>Duration (min)</label><input type="number" id="newDuration" min="1" value="15" style="width:80px"></div>
                <div class="fg"><label>Winner Payout %</label><input type="number" id="newPayout" min="1" max="99" value="70" style="width:80px"></div>
                <button class="btn-create" onclick="createTicket()">Create Ticket</button>
            </div>
        </div>

        <div class="mode-section">
            <h2>1 vs 1 Tickets <span class="mode-badge mode-1vs1">1vs1 · 2 players</span></h2>
            <div class="ticket-grid" id="grid1vs1"><div class="empty-msg">Loading...</div></div>
        </div>
        <div class="mode-section">
            <h2>1 vs 4 Tickets <span class="mode-badge mode-1vs4">1vs4 · 4 players</span></h2>
            <div class="ticket-grid" id="grid1vs4"><div class="empty-msg">Loading...</div></div>
        </div>
    </div>
    <div class="toast" id="adminToast"></div>
    <script>
        const BASE_PATH = '<?php echo $basePath; ?>';
        let CSRF = '<?php echo $csrf_token; ?>';
        
        function showToast(m,t='info'){const el=document.getElementById('adminToast');el.textContent=m;el.className='toast '+t+' show';clearTimeout(el._to);el._to=setTimeout(()=>el.classList.remove('show'),4000)}
        function escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML}

        // 🔥 CSRF Token Auto-Refresh
        async function refreshCsrfToken() {
            try {
                const resp = await fetch(BASE_PATH + '/api/auth.php?action=get_csrf', {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await resp.json();
                if (data.success && data.data && data.data.csrf_token) {
                    CSRF = data.data.csrf_token;
                    return data.data.csrf_token;
                }
                return CSRF;
            } catch (e) {
                console.warn('[CSRF] Refresh error:', e);
                return CSRF;
            }
        }

        // 🔥 Universal POST with Auto CSRF
        async function adminPost(url, body = {}) {
            try {
                const freshToken = await refreshCsrfToken();
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': freshToken
                    },
                    body: JSON.stringify({ ...body, csrf_token: freshToken })
                });
                const data = await response.json();
                
                if (!data.success && data.data && data.data.refresh_needed) {
                    const retryToken = data.data.csrf_token || await refreshCsrfToken();
                    CSRF = retryToken;
                    const retryResponse = await fetch(url, {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': retryToken
                        },
                        body: JSON.stringify({ ...body, csrf_token: retryToken })
                    });
                    return await retryResponse.json();
                }
                return data;
            } catch (e) {
                console.error('[Admin POST] Error:', e);
                return { success: false, message: 'Network error' };
            }
        }

        function loadTickets() {
            fetch(BASE_PATH + '/api/tickets.php?action=admin_list', { credentials: 'include' }).then(r=>r.json()).then(d=>{
                if (!d.success) { showToast(d.message || 'Error loading tickets', 'error'); return; }
                const g1 = d.data.tickets.filter(t => t.game_mode === '1vs1');
                const g4 = d.data.tickets.filter(t => t.game_mode === '1vs4');
                document.getElementById('grid1vs1').innerHTML = g1.length ? g1.map(renderCard).join('') : '<div class="empty-msg">No 1vs1 tickets yet — create one above.</div>';
                document.getElementById('grid1vs4').innerHTML = g4.length ? g4.map(renderCard).join('') : '<div class="empty-msg">No 1vs4 tickets yet — create one above.</div>';
            }).catch(()=>showToast('Network error', 'error'));
        }

        function renderCard(t) {
            const active = !!parseInt(t.is_active);
            return `<div class="ticket-card ${active?'':'inactive'}">
                <div class="tc-fee">₹${parseFloat(t.entry_fee).toFixed(0)}</div>
                <div class="tc-row"><span>Duration</span><span>${t.duration_minutes} min</span></div>
                <div class="tc-row"><span>Winner Payout</span><span>${parseFloat(t.payout_percent).toFixed(0)}%</span></div>
                <div class="tc-row"><span>Total Matches</span><span>${t.total_matches || 0}</span></div>
                ${parseInt(t.waiting_count) > 0 ? `<div class="tc-waiting">⏳ ${t.waiting_count} player(s) waiting right now</div>` : ''}
                <div class="tc-actions"><button class="tc-toggle ${active?'active-btn':'inactive-btn'}" onclick="toggleTicket(${t.id})">${active?'Deactivate':'Activate'}</button></div>
            </div>`;
        }

        async function createTicket() {
            const mode = document.getElementById('newMode').value;
            const fee = parseFloat(document.getElementById('newFee').value || '0');
            const duration = parseInt(document.getElementById('newDuration').value || '15');
            const payout = parseFloat(document.getElementById('newPayout').value || '70');
            if (!fee || fee <= 0) { showToast('Enter a valid entry fee', 'error'); return; }
            
            const d = await adminPost(BASE_PATH + '/api/tickets.php?action=admin_create', {
                game_mode: mode,
                entry_fee: fee,
                duration_minutes: duration,
                payout_percent: payout
            });
            
            if (d.success) { showToast('Ticket created!', 'success'); document.getElementById('newFee').value=''; loadTickets(); }
            else showToast(d.message || 'Failed', 'error');
        }

        async function toggleTicket(id) {
            const d = await adminPost(BASE_PATH + '/api/tickets.php?action=admin_toggle', {
                ticket_id: id
            });
            
            if (d.success) { showToast('Updated', 'success'); loadTickets(); }
            else showToast(d.message || 'Failed', 'error');
        }

        document.addEventListener('DOMContentLoaded', () => { loadTickets(); setInterval(loadTickets, 10000); });
    </script>
</body>
</html>