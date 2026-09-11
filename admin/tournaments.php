<?php
/**
 * ======================================================
 * ADMIN TOURNAMENTS.PHP - Tournament Management Dashboard
 * Ludo Tournament Platform - Admin Tournament Control
 * Version: 4.2.0 - CSRF AUTO-REFRESH FIX
 * ======================================================
 */

if (!defined('BASE_PATH')) { define('BASE_PATH', dirname(__DIR__)); }
require_once dirname(__DIR__) . '/config/db.php';
SessionManager::init();

function validateAdminSession() {
    if (!SessionManager::has('admin_id') || !SessionManager::has('admin_token')) return false;
    try {
        $db = Database::getInstance(); $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => SessionManager::get('admin_id'), ':token' => SessionManager::get('admin_token')]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}
if (!validateAdminSession()) { SessionManager::destroy(); header('Location: index.php'); exit; }

$csrf_token = CSRFToken::generate();
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/admin') $basePath = '';

try {
    $db = Database::getInstance(); $conn = $db->getConnection();
} catch (Exception $e) { die("Database connection failed"); }

$success = ''; $error = '';
const VALID_GAME_MODES = ['1vs1', '1vs4'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_tournament'])) {
        $postedCsrf = $_POST['csrf_token'] ?? '';
        if (!$postedCsrf || !CSRFToken::validate($postedCsrf)) {
            $error = "Your session has expired or the form is invalid. Please refresh and try again.";
        } else {
        $name = trim($_POST['name'] ?? '');
        $gameMode = $_POST['game_mode'] ?? '1vs1';
        $entryFee = floatval($_POST['entry_fee'] ?? 0);
        $totalPlayers = intval($_POST['total_players'] ?? 2);
        $firstPrize = floatval($_POST['first_prize_percent'] ?? 60);
        $secondPrize = floatval($_POST['second_prize_percent'] ?? 30);
        $thirdPrize = floatval($_POST['third_prize_percent'] ?? 10);
        $scoringMode = in_array($_POST['scoring_mode'] ?? 'winner_takes_all', ['winner_takes_all', 'points_timed'], true)
            ? $_POST['scoring_mode'] : 'winner_takes_all';
        $timeLimitMinutes = $scoringMode === 'points_timed' ? max(1, min(120, intval($_POST['time_limit_minutes'] ?? 15))) : null;
        
        if (empty($name)) $error = "Name required";
        elseif (mb_strlen($name) > 100) $error = "Name too long (max 100 characters)";
        elseif (!in_array($gameMode, VALID_GAME_MODES, true)) $error = "Invalid game mode";
        elseif ($entryFee <= 0) $error = "Invalid entry fee";
        elseif ($totalPlayers < 2 || $totalPlayers > 20000) $error = "Total players must be between 2 and 20,000";
        elseif ($scoringMode === 'winner_takes_all' && ($firstPrize < 0 || $secondPrize < 0 || $thirdPrize < 0)) $error = "Prize percentages cannot be negative";
        elseif ($scoringMode === 'winner_takes_all' && ($firstPrize + $secondPrize + $thirdPrize) > 100) $error = "Prize % exceeds 100";
        else {
            $maxPlayers = $gameMode === '1vs1' ? 2 : 4;
            $totalPool = $entryFee * $totalPlayers;
            $platformFee = $totalPool * (PLATFORM_FEE / 100);
            $prizePool = $totalPool - $platformFee;
            $tournamentCode = 'T' . strtoupper(bin2hex(random_bytes(4)));
            
            try {
                $stmt = $conn->prepare("
                    INSERT INTO tournaments (tournament_code, name, game_mode, entry_fee, prize_pool, platform_fee, max_players, total_players, min_players, first_prize_percent, second_prize_percent, third_prize_percent, first_prize_amount, second_prize_amount, third_prize_amount, scoring_mode, time_limit_minutes, status, created_by, created_at, updated_at)
                    VALUES (:code, :name, :mode, :fee, :prize, :pf, :max, :total, 2, :fp, :sp, :tp, :fa, :sa, :ta, :smode, :tlimit, 'scheduled', :admin, NOW(), NOW())
                ");
                $stmt->execute([
                    ':code' => $tournamentCode, ':name' => $name, ':mode' => $gameMode,
                    ':fee' => $entryFee, ':prize' => $prizePool, ':pf' => $platformFee,
                    ':max' => $maxPlayers, ':total' => $totalPlayers,
                    ':fp' => $firstPrize, ':sp' => $secondPrize, ':tp' => $thirdPrize,
                    ':fa' => round($prizePool*($firstPrize/100), 2),
                    ':sa' => round($prizePool*($secondPrize/100), 2),
                    ':ta' => round($prizePool*($thirdPrize/100), 2),
                    ':smode' => $scoringMode, ':tlimit' => $timeLimitMinutes,
                    ':admin' => SessionManager::get('admin_id')
                ]);
                $success = "✅ Tournament '" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "' created!";
            } catch (Exception $e) {
                error_log('[Admin Tournament Create Error] ' . $e->getMessage());
                $error = "A server error occurred while creating the tournament.";
            }
        }
        }
    }
}

// Fetch tournaments
$tournaments = [];
try {
    $stmt = $conn->query("
        SELECT t.*, u.username as created_by_name,
               (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.id) as registered_count
        FROM tournaments t LEFT JOIN users u ON t.created_by = u.id
        ORDER BY t.created_at DESC
    ");
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $tournaments = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Management - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Roboto Slab',serif;background:#FFFCF8;color:#000000;min-height:100vh}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#7D02AB;border-radius:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:800;color:#FFFFFF}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions span{color:#FFFFFF;font-weight:700}
        .admin-header-actions a,.admin-header-actions button{color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.3);border-radius:8px;background:transparent;cursor:pointer;font-family:'Roboto Slab',serif}
        .admin-header-actions a:hover{background:rgba(255,255,255,0.15)}
        .admin-header-actions a.logout{color:#FF6B6B;border-color:rgba(255,100,100,0.4)}
        .btn-primary{padding:10px 24px;border:none;border-radius:10px;background:#7D02AB;color:#FFFFFF;font-weight:800;font-size:14px;cursor:pointer;font-family:'Roboto Slab',serif}
        .btn-success{padding:6px 14px;border:none;border-radius:6px;background:rgba(16,185,129,0.2);color:#047857;font-weight:700;font-size:12px;cursor:pointer;font-family:'Roboto Slab',serif}
        .btn-warning{padding:6px 14px;border:none;border-radius:6px;background:rgba(245,158,11,0.2);color:#B45309;font-weight:700;font-size:12px;cursor:pointer;font-family:'Roboto Slab',serif}
        .btn-danger{padding:6px 14px;border:none;border-radius:6px;background:rgba(239,68,68,0.2);color:#B91C1C;font-weight:700;font-size:12px;cursor:pointer;font-family:'Roboto Slab',serif}
        .btn-info{padding:6px 14px;border:none;border-radius:6px;background:rgba(59,130,246,0.2);color:#2563EB;font-weight:700;font-size:12px;cursor:pointer;font-family:'Roboto Slab',serif}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#FFFFFF;padding:32px;border-radius:16px;max-width:550px;width:100%;border:1px solid rgba(125,2,171,0.1);max-height:90vh;overflow-y:auto;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .modal-box h2{font-size:20px;font-weight:800;margin-bottom:16px;color:#000000}
        .form-group{margin-bottom:14px}.form-group label{display:block;font-size:13px;font-weight:700;color:#000000;margin-bottom:4px}
        .form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-size:14px;font-family:'Roboto Slab',serif}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:#7D02AB}
        .form-group select option{background:#FFFFFF;color:#000000}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .hint{font-size:11px;color:#555555;margin-top:4px;font-weight:700}
        .info-box{background:rgba(125,2,171,0.08);border:1px solid rgba(125,2,171,0.2);border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#7D02AB;font-weight:700}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:800;font-size:14px;cursor:pointer;font-family:'Roboto Slab',serif}
        .modal-actions .btn-confirm{background:#7D02AB;color:#FFFFFF}
        .modal-actions .btn-cancel{background:#F5E6FF;color:#000000}
        .table-container{background:#FFFFFF;border-radius:14px;border:1px solid rgba(125,2,171,0.1);overflow-x:auto;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        table{width:100%;border-collapse:collapse;font-size:14px}
        table th{padding:12px 16px;text-align:left;color:#FFFFFF;background:#7D02AB;font-weight:800;font-size:12px;text-transform:uppercase}
        table td{padding:12px 16px;border-bottom:1px solid rgba(125,2,171,0.05);color:#000000}
        .status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
        .status-badge.scheduled{background:rgba(148,163,184,0.15);color:#555555}
        .status-badge.active{background:rgba(16,185,129,0.15);color:#047857}
        .status-badge.in_progress{background:rgba(59,130,246,0.15);color:#2563EB}
        .status-badge.completed{background:rgba(16,185,129,0.15);color:#047857}
        .game-mode-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
        .game-mode-badge.vs1{background:rgba(125,2,171,0.15);color:#7D02AB}
        .game-mode-badge.vs4{background:rgba(245,158,11,0.15);color:#B45309}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:800;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}.toast.success{background:rgba(16,185,129,0.2);color:#047857}.toast.error{background:rgba(239,68,68,0.2);color:#B91C1C}
        .prize-modal .modal-box{max-width:650px}
        .winner-inputs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px}
        @media(max-width:768px){.form-row{grid-template-columns:1fr}.winner-inputs{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>🏆 Tournament Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars(SessionManager::get('admin_username', 'Admin')); ?></span>
                <a href="index.php">← Dashboard</a>
                <a href="admin_users.php">👥 Users</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="tickets.php">🎟️ Tickets</a><a href="withdrawals.php">🏦 Withdrawals</a><a href="deposits.php">💰 Deposits</a>
                <a href="disputes.php">📋 Disputes</a>
                <button class="btn-primary" onclick="openCreateModal()">➕ New Tournament</button>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <?php if($success): ?><div style="background:rgba(16,185,129,0.1);color:#047857;padding:12px;border-radius:8px;margin-bottom:16px;font-weight:700"><?php echo $success; ?></div><?php endif; ?>
        <?php if($error): ?><div style="background:rgba(239,68,68,0.1);color:#B91C1C;padding:12px;border-radius:8px;margin-bottom:16px;font-weight:700"><?php echo $error; ?></div><?php endif; ?>
        
        <div class="table-container">
            <table><thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Mode</th><th>Entry</th><th>Prize Pool</th><th>Players</th><th>1st/2nd/3rd %</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody><?php if(empty($tournaments)): ?><tr><td colspan="10" style="text-align:center;padding:40px;color:#000000">No tournaments created yet</td></tr>
            <?php else: foreach($tournaments as $t): ?>
                <tr>
                    <td>#<?php echo $t['id']; ?></td>
                    <td><code><?php echo htmlspecialchars($t['tournament_code']); ?></code></td>
                    <td><?php echo htmlspecialchars($t['name']); ?></td>
                    <td><span class="game-mode-badge <?php echo $t['game_mode']==='1vs1'?'vs1':'vs4'; ?>"><?php echo $t['game_mode']; ?></span></td>
                    <td style="color:#7D02AB;font-weight:700">₹<?php echo number_format($t['entry_fee'],2); ?></td>
                    <td style="color:#047857;font-weight:700">₹<?php echo number_format($t['prize_pool'],2); ?></td>
                    <td><?php echo $t['registered_count']; ?>/<?php echo $t['total_players']; ?></td>
                    <td><?php echo $t['first_prize_percent']; ?>%/<?php echo $t['second_prize_percent']; ?>%/<?php echo $t['third_prize_percent']; ?>%</td>
                    <td><span class="status-badge <?php echo $t['status']; ?>"><?php echo ucwords(str_replace('_',' ',$t['status'])); ?></span></td>
                    <td>
                        <?php if($t['status']==='scheduled'): ?><button class="btn-success" onclick="activateTournament(<?php echo $t['id']; ?>)">Activate</button><?php endif; ?>
                        <?php if(in_array($t['status'],['scheduled','active'])): ?><button class="btn-warning" onclick="startTournament(<?php echo $t['id']; ?>)">Start</button><?php endif; ?>
                        <?php if($t['status']==='in_progress'): ?><button class="btn-info" onclick="openPrizeModal(<?php echo $t['id']; ?>)">🏆 Prizes</button><?php endif; ?>
                        <?php if(in_array($t['status'],['active','in_progress'])): ?><button class="btn-danger" onclick="endTournament(<?php echo $t['id']; ?>)">⏹️ Cancel</button><?php endif; ?>
                        <?php if($t['status']==='scheduled'): ?><button class="btn-danger" onclick="deleteTournament(<?php echo $t['id']; ?>)">Delete</button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
    </div>
    
    <!-- CREATE TOURNAMENT MODAL -->
    <div class="modal-overlay" id="createModal">
        <div class="modal-box">
            <h2>➕ Create New Tournament</h2>
            <form method="POST">
                <div class="form-group"><label>Tournament Name *</label><input type="text" name="name" required placeholder="e.g., ₹50 Mega Cup"></div>
                <div class="form-row">
                    <div class="form-group"><label>Game Mode *</label><select name="game_mode" id="gameMode" onchange="updateMaxPlayers()"><option value="1vs1">1 vs 1 (Duel)</option><option value="1vs4">1 vs 4 (Battle Royale)</option></select></div>
                    <div class="form-group"><label>Entry Fee (₹) *</label><input type="number" name="entry_fee" id="entryFee" step="1" min="1" required onchange="calculatePrizes()"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Total Players *</label><input type="number" name="total_players" id="totalPlayers" min="2" max="20000" value="100" required onchange="calculatePrizes()"></div>
                    <div class="form-group"><label>Tournament Type *</label><select name="scoring_mode" id="scoringMode" onchange="toggleScoringModeFields()">
                        <option value="winner_takes_all">Classic — First to finish all 4 tokens wins</option>
                        <option value="points_timed">Speed Ludo — Point-scored, 15-min timer, Top-100 payout</option>
                    </select></div>
                    <div class="form-group" id="timeLimitGroup" style="display:none;"><label>Time Limit (minutes)</label><input type="number" name="time_limit_minutes" id="timeLimitMinutes" min="1" max="120" value="15"></div>
                    <div class="form-group"><label>Max Players Per Match</label><input type="text" id="maxPlayersDisplay" value="2" disabled></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>1st Prize %</label><input type="number" name="first_prize_percent" id="fp" value="60" min="1" max="100" onchange="calculatePrizes()"></div>
                    <div class="form-group"><label>2nd Prize %</label><input type="number" name="second_prize_percent" id="sp" value="30" min="0" max="100" onchange="calculatePrizes()"></div>
                    <div class="form-group"><label>3rd Prize %</label><input type="number" name="third_prize_percent" id="tp" value="10" min="0" max="100" onchange="calculatePrizes()"></div>
                </div>
                <div class="info-box" id="prizeInfo">
                    <strong>Prize Calculation:</strong><br>
                    Total Pool: ₹<span id="calcTotal">0</span> | 
                    Platform Fee (<?php echo PLATFORM_FEE; ?>%): ₹<span id="calcFee">0</span><br>
                    1st: ₹<span id="calc1st">0</span> | 
                    2nd: ₹<span id="calc2nd">0</span> | 
                    3rd: ₹<span id="calc3rd">0</span>
                </div>
                <input type="hidden" name="create_tournament" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-actions"><button type="submit" class="btn-confirm">✅ Create Tournament</button><button type="button" class="btn-cancel" onclick="closeModal('createModal')">Cancel</button></div>
            </form>
        </div>
    </div>
    
    <!-- PRIZE DISTRIBUTION MODAL -->
    <div class="modal-overlay prize-modal" id="prizeModal">
        <div class="modal-box">
            <h2>🏆 Distribute Prizes</h2>
            <input type="hidden" id="prizeTournamentId">
            <div class="info-box" id="prizeDistInfo"></div>
            <div class="winner-inputs">
                <div class="form-group"><label>🥇 1st Winner User ID</label><input type="number" id="firstWinnerId" placeholder="User ID"></div>
                <div class="form-group"><label>🥈 2nd Winner User ID</label><input type="number" id="secondWinnerId" placeholder="User ID"></div>
                <div class="form-group"><label>🥉 3rd Winner User ID</label><input type="number" id="thirdWinnerId" placeholder="User ID"></div>
            </div>
            <div class="modal-actions"><button class="btn-confirm" onclick="distributePrizes()">💸 Distribute Prizes</button><button class="btn-cancel" onclick="closeModal('prizeModal')">Cancel</button></div>
        </div>
    </div>
    
    <div class="toast" id="adminToast"></div>
    
    <script>
        const PLATFORM_FEE = <?php echo PLATFORM_FEE; ?>;
        let csrfToken = '<?php echo $csrf_token; ?>';
        
        // 🔥 CSRF Token Auto-Refresh
        async function refreshCsrfToken() {
            try {
                const resp = await fetch('<?php echo $basePath; ?>/api/auth.php?action=get_csrf', {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await resp.json();
                if (data.success && data.data && data.data.csrf_token) {
                    csrfToken = data.data.csrf_token;
                    return data.data.csrf_token;
                }
                return csrfToken;
            } catch (e) {
                console.warn('[CSRF] Refresh error:', e);
                return csrfToken;
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
                    csrfToken = retryToken;
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
        
        function showToast(m,t){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';setTimeout(()=>toast.classList.remove('show'),4000)}
        function escapeHtml(s){if(s===null||s===undefined)return'';const d=document.createElement('div');d.textContent=String(s);return d.innerHTML}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        function openCreateModal(){document.getElementById('createModal').classList.add('active');calculatePrizes()}
        function updateMaxPlayers(){document.getElementById('maxPlayersDisplay').value=document.getElementById('gameMode').value==='1vs1'?'2':'4'}
        function toggleScoringModeFields(){
            const isTimed = document.getElementById('scoringMode').value === 'points_timed';
            document.getElementById('timeLimitGroup').style.display = isTimed ? 'block' : 'none';
            ['fp','sp','tp'].forEach(id => {
                const group = document.getElementById(id).closest('.form-group');
                if (group) group.style.display = isTimed ? 'none' : 'block';
            });
        }
        
        function calculatePrizes(){
            const fee=parseFloat(document.getElementById('entryFee').value)||0;
            const players=parseInt(document.getElementById('totalPlayers').value)||0;
            const fp=parseFloat(document.getElementById('fp').value)||0;
            const sp=parseFloat(document.getElementById('sp').value)||0;
            const tp=parseFloat(document.getElementById('tp').value)||0;
            const total=fee*players;
            const pfee=total*(PLATFORM_FEE/100);
            const net=total-pfee;
            document.getElementById('calcTotal').textContent=total.toFixed(2);
            document.getElementById('calcFee').textContent=pfee.toFixed(2);
            document.getElementById('calc1st').textContent=(net*(fp/100)).toFixed(2);
            document.getElementById('calc2nd').textContent=(net*(sp/100)).toFixed(2);
            document.getElementById('calc3rd').textContent=(net*(tp/100)).toFixed(2);
        }
        
        async function activateTournament(id){
            if(!confirm('Activate this tournament?'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/tournament_system.php?action=admin_update', { id: id, status: 'active' });
            if(d.success){showToast('Tournament activated!','success');setTimeout(()=>location.reload(),1000)}
            else showToast(d.message||'Failed','error');
        }
        
        async function startTournament(id){
            if(!confirm('Start this tournament?'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/tournament_system.php?action=admin_start', { id: id });
            if(d.success){showToast('Tournament started!','success');setTimeout(()=>location.reload(),1000)}
            else showToast(d.message||'Failed','error');
        }
        
        async function endTournament(id){
            if(!confirm('Cancel/end this tournament? No prizes will be paid out.'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/tournament_system.php?action=admin_end', { id: id });
            if(d.success){showToast('Tournament ended','success');setTimeout(()=>location.reload(),1000)}
            else showToast(d.message||'Failed','error');
        }
        
        async function deleteTournament(id){
            if(!confirm('Delete this tournament? This cannot be undone.'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/tournament_system.php?action=admin_delete', { id: id });
            if(d.success){showToast('Deleted!','success');setTimeout(()=>location.reload(),1000)}
            else showToast(d.message||'Failed','error');
        }
        
        function openPrizeModal(id){
            document.getElementById('prizeTournamentId').value=id;
            document.getElementById('firstWinnerId').value='';
            document.getElementById('secondWinnerId').value='';
            document.getElementById('thirdWinnerId').value='';
            fetch(`<?php echo $basePath; ?>/api/tournament_system.php?action=get_tournament&id=${id}`, { credentials: 'include' }).then(r=>r.json()).then(d=>{
                if(d.success){
                    const t=d.data.tournament;
                    document.getElementById('prizeDistInfo').innerHTML=`<strong>${escapeHtml(t.name)}</strong><br>Mode: ${escapeHtml(t.game_mode)} | Entry: ₹${parseFloat(t.entry_fee).toFixed(2)} | Players: ${parseInt(t.total_players)}<br>1st (${parseFloat(t.first_prize_percent)}%): ₹${parseFloat(t.calculated_first_prize).toFixed(2)} | 2nd (${parseFloat(t.second_prize_percent)}%): ₹${parseFloat(t.calculated_second_prize).toFixed(2)} | 3rd (${parseFloat(t.third_prize_percent)}%): ₹${parseFloat(t.calculated_third_prize).toFixed(2)}`;
                }
            });
            document.getElementById('prizeModal').classList.add('active');
        }
        
        async function distributePrizes(){
            const id=document.getElementById('prizeTournamentId').value;
            const fw=document.getElementById('firstWinnerId').value;
            const sw=document.getElementById('secondWinnerId').value;
            const tw=document.getElementById('thirdWinnerId').value;
            if(!fw||parseInt(fw)<=0){showToast('Enter a valid 1st winner ID','error');return}
            const d = await adminPost('<?php echo $basePath; ?>/api/tournament_system.php?action=admin_distribute_prizes', {
                id: parseInt(id),
                first_winner_id: parseInt(fw),
                second_winner_id: parseInt(sw||0),
                third_winner_id: parseInt(tw||0)
            });
            if(d.success){showToast('Prizes distributed!','success');closeModal('prizeModal');setTimeout(()=>location.reload(),1500)}
            else showToast(d.message||'Failed','error');
        }
    </script>
</body>
</html>