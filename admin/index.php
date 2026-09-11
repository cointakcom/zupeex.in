<?php
/**
 * ======================================================
 * ADMIN INDEX.PHP - Pro Command Center (CSRF FIXED)
 * Ludo Tournament Platform - Admin Dashboard
 * Version: 5.3.0 - CSRF AUTO-REFRESH FIX
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

SessionManager::init();

// Check admin login
$isAdminLoggedIn = false;
$adminData = null;

if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_token'])) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.is_admin, u.is_active, u.last_login,
                   s.session_token as db_token, s.expires_at
            FROM users u
            LEFT JOIN sessions s ON u.id = s.user_id AND s.is_active = 1
            WHERE u.id = :aid AND u.is_admin = 1 AND u.is_active = 1
        ");
        $stmt->execute([':aid' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && $admin['db_token'] === $_SESSION['admin_token']) {
            if (strtotime($admin['expires_at']) > time()) {
                $isAdminLoggedIn = true;
                $adminData = $admin;
                
                $stmt = $conn->prepare("UPDATE sessions SET last_activity = CURRENT_TIMESTAMP WHERE user_id = :aid AND is_active = 1");
                $stmt->execute([':aid' => $_SESSION['admin_id']]);
            }
        }
    } catch (Exception $e) {
        $isAdminLoggedIn = false;
    }
}

// Handle login
if (!$isAdminLoggedIn && isset($_POST['admin_login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("SELECT id, username, password_hash, is_admin, is_active FROM users WHERE username = :uname AND is_admin = 1");
            $stmt->execute([':uname' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && $user['is_active'] == 1 && password_verify($password, $user['password_hash'])) {
                $db->beginTransaction();
                
                $adminToken = bin2hex(random_bytes(64));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+8 hours'));
                
                $stmt = $conn->prepare("INSERT INTO sessions (user_id, session_token, ip_address, user_agent, device_type, expires_at, is_active, created_at) VALUES (:uid, :token, :ip, :ua, :dev, :exp, 1, CURRENT_TIMESTAMP)");
                $stmt->execute([
                    ':uid' => $user['id'], ':token' => $adminToken,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    ':dev' => 'Admin Panel', ':exp' => $expiresAt
                ]);
                
                $stmt = $conn->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :uid");
                $stmt->execute([':uid' => $user['id']]);
                
                $db->commit();
                
                session_regenerate_id(true);
                
                // 🔥 FIX: Login ke baad naya CSRF token generate karo
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['csrf_token_time'] = time();
                
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_token'] = $adminToken;
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_logged_in'] = true;
                
                header('Location: index.php');
                exit;
            } else {
                $loginError = 'Invalid username or password';
            }
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) $db->rollback();
            error_log('[Admin Login Error] ' . $e->getMessage());
            $loginError = 'A system error occurred. Please try again or contact support.';
        }
    } else {
        $loginError = 'Please enter username and password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['admin_id'])) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            $stmt = $conn->prepare("UPDATE sessions SET is_active = 0 WHERE user_id = :uid AND session_token = :token");
            $stmt->execute([':uid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token'] ?? '']);
        } catch (Exception $e) {}
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

$csrf_token = $isAdminLoggedIn ? CSRFToken::generate() : '';

if ($isAdminLoggedIn && isset($_GET['ajax'])) {
    handleAdminAjax();
    exit;
}

const ADMIN_WRITE_ACTIONS = ['update_balance', 'toggle_user'];

function handleAdminAjax() {
    $action = $_GET['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
        if (!$stmt->fetch()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Session expired', 'redirect' => true]);
            exit;
        }

        if (in_array($action, ADMIN_WRITE_ACTIONS, true)) {
            $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $bodyToken = '';
            if (!$headerToken) {
                $rawInput = json_decode(file_get_contents('php://input'), true);
                $bodyToken = $rawInput['csrf_token'] ?? '';
            }
            $providedToken = $headerToken ?: $bodyToken;
            if (!$providedToken || !CSRFToken::validate($providedToken)) {
                http_response_code(403);
                echo json_encode([
                    'success' => false, 
                    'message' => 'Invalid or missing CSRF token',
                    'data' => [
                        'csrf_token' => CSRFToken::generate(),
                        'refresh_needed' => true
                    ]
                ]);
                exit;
            }
        }
        
        switch ($action) {
            case 'get_stats': $response = getAdminStats($conn); break;
            case 'get_users': $response = getUsersList($conn); break;
            case 'update_balance': $response = updateUserBalance($db, $conn); break;
            case 'get_transactions': $response = getUserTransactions($conn); break;
            case 'toggle_user': $response = toggleUserStatus($conn); break;
            case 'get_matches': $response = getMatchesList($conn); break;
        }
    } catch (Exception $e) {
        error_log('[Admin AJAX Error] action=' . $action . ' - ' . $e->getMessage());
        $response['message'] = 'A server error occurred. Please try again.';
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function getAdminStats($conn) {
    $stats = [];
    $stats['total_users'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 0")->fetchColumn());
    $stats['active_users'] = intval($conn->query("SELECT COUNT(DISTINCT user_id) FROM transactions WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn());
    $stats['new_users_today'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND is_admin = 0")->fetchColumn());
    $stats['total_matches'] = intval($conn->query("SELECT COUNT(*) FROM matches")->fetchColumn());
    $stats['pending_kyc'] = intval($conn->query("SELECT COUNT(*) FROM kyc_documents WHERE status = 'pending'")->fetchColumn());
    $stats['pending_withdrawals'] = intval($conn->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn());
    $stats['open_disputes'] = intval($conn->query("SELECT COUNT(*) FROM dispute_tickets WHERE status IN ('open','investigating')")->fetchColumn());
    $stats['total_platform_revenue'] = floatval($conn->query("SELECT SUM(amount) FROM transactions WHERE source = 'deposit' AND status = 'success'")->fetchColumn());
    $stats['total_user_balance'] = floatval($conn->query("SELECT SUM(wallet_balance) FROM users WHERE is_admin = 0")->fetchColumn());
    return ['success' => true, 'data' => $stats];
}

function getUsersList($conn) {
    $offset = intval($_GET['offset'] ?? 0);
    $limit = intval($_GET['limit'] ?? 50);
    $search = trim($_GET['search'] ?? '');
    $active = $_GET['active'] ?? '';
    
    $where = "WHERE is_admin = 0";
    $params = [];
    
    if ($search) {
        $where .= " AND (username LIKE :search OR mobile LIKE :search OR email LIKE :search)";
        $params[':search'] = "%$search%";
    }
    if ($active === '1') $where .= " AND is_active = 1";
    if ($active === '0') $where .= " AND is_active = 0";
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users $where");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    
    $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance, is_active, kyc_status, created_at, total_matches_played, total_matches_won, total_earnings FROM users $where ORDER BY id DESC LIMIT $offset, $limit");
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return ['success' => true, 'data' => ['users' => $users, 'total' => $total]];
}

function updateUserBalance($db, $conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = intval($input['user_id'] ?? 0);
    $amount = floatval($input['amount'] ?? 0);
    $type = $input['type'] ?? 'credit';
    $reason = $input['reason'] ?? 'Admin adjustment';
    
    if ($userId <= 0 || $amount <= 0) {
        return ['success' => false, 'message' => 'Invalid input'];
    }
    
    $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) return ['success' => false, 'message' => 'User not found'];
    
    $newBalance = $type === 'credit' ? $user['wallet_balance'] + $amount : $user['wallet_balance'] - $amount;
    if ($newBalance < 0) return ['success' => false, 'message' => 'Insufficient balance'];
    
    $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
    $stmt->execute([$newBalance, $userId]);
    
    $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, source, description, status, created_at) VALUES (?, ?, ?, 'admin_adjustment', ?, 'success', NOW())");
    $stmt->execute([$userId, $amount, $type, $reason]);
    
    return ['success' => true, 'message' => 'Balance updated'];
}

function getUserTransactions($conn) {
    $userId = intval($_GET['user_id'] ?? 0);
    $limit = intval($_GET['limit'] ?? 30);
    
    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT $limit");
        $stmt->execute([$userId]);
    } else {
        $stmt = $conn->query("SELECT * FROM transactions ORDER BY id DESC LIMIT $limit");
    }
    
    return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

function toggleUserStatus($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = intval($input['user_id'] ?? 0);
    
    $stmt = $conn->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ? AND is_admin = 0");
    $stmt->execute([$userId]);
    
    return ['success' => true, 'message' => 'Status updated'];
}

function getMatchesList($conn) {
    $stmt = $conn->query("SELECT m.*, u1.username as player1_name, u2.username as player2_name FROM matches m LEFT JOIN users u1 ON m.player1_id = u1.id LEFT JOIN users u2 ON m.player2_id = u2.id ORDER BY m.id DESC LIMIT 50");
    return ['success' => true, 'data' => ['matches' => $stmt->fetchAll(PDO::FETCH_ASSOC)]];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Command Center - Zupeex</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Roboto Slab',serif;background:#FFFCF8;color:#000000;min-height:100vh}
        .login-container{display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px}
        .login-box{background:#FFFFFF;padding:40px;border-radius:20px;max-width:400px;width:100%;border:1px solid rgba(125,2,171,0.1);box-shadow:0 20px 60px rgba(125,2,171,0.15)}
        .login-box h1{font-size:28px;font-weight:800;margin-bottom:8px;color:#7D02AB}
        .login-box p{color:#555555;margin-bottom:24px}
        .login-box .form-group{margin-bottom:16px}
        .login-box .form-group label{display:block;font-size:13px;font-weight:700;color:#000000;margin-bottom:4px}
        .login-box .form-group input{width:100%;padding:12px 14px;border:1px solid rgba(125,2,171,0.2);border-radius:10px;background:#FFFFFF;color:#000000;font-size:14px;font-family:'Roboto Slab',serif}
        .login-box .form-group input:focus{outline:none;border-color:#7D02AB}
        .login-btn{width:100%;padding:14px;border:none;border-radius:10px;background:#7D02AB;color:#FFFFFF;font-weight:800;font-size:16px;cursor:pointer;font-family:'Roboto Slab',serif}
        .login-btn:hover{transform:scale(1.02);box-shadow:0 0 30px rgba(125,2,171,0.3)}
        .login-error{color:#DC2626;font-size:14px;margin-bottom:16px;padding:10px;background:rgba(239,68,68,0.1);border-radius:8px}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#7D02AB;border-radius:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:800;color:#FFFFFF}
        .admin-header-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .admin-header-actions span{color:#FFFFFF;font-weight:700}
        .admin-header-actions a{color:#FFFFFF;text-decoration:none;font-weight:700;font-size:13px;padding:8px 14px;border:1px solid rgba(255,255,255,0.3);border-radius:8px}
        .admin-header-actions a:hover{background:rgba(255,255,255,0.15)}
        .admin-header-actions a.logout{color:#FF6B6B;border-color:rgba(255,100,100,0.4)}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px}
        .stat-card{background:#7D02AB;padding:16px 20px;border-radius:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);text-align:center}
        .stat-card .stat-label{font-size:11px;color:#FFFFFF;text-transform:uppercase;letter-spacing:0.5px;font-weight:700}
        .stat-card .stat-value{font-size:22px;font-weight:800;margin-top:4px;color:#FFFFFF}
        .table-container{background:#FFFFFF;border-radius:14px;border:1px solid rgba(125,2,171,0.1);overflow-x:auto;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        table{width:100%;border-collapse:collapse;font-size:14px}
        table th{padding:12px 16px;text-align:left;color:#FFFFFF;background:#7D02AB;font-weight:800;font-size:12px;text-transform:uppercase}
        table td{padding:12px 16px;border-bottom:1px solid rgba(125,2,171,0.05);color:#000000}
        .status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
        .status-badge.success{background:rgba(16,185,129,0.15);color:#047857}
        .status-badge.failed{background:rgba(239,68,68,0.15);color:#B91C1C}
        .btn-action{padding:4px 12px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Roboto Slab',serif;margin:0 2px}
        .btn-action.primary{background:rgba(59,130,246,0.2);color:#2563EB}
        .btn-action.danger{background:rgba(239,68,68,0.2);color:#B91C1C}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#FFFFFF;padding:32px;border-radius:16px;max-width:500px;width:100%;border:1px solid rgba(125,2,171,0.1);box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .modal-box h2{font-size:20px;font-weight:800;margin-bottom:16px;color:#000000}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#000000;margin-bottom:4px}
        .form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-size:14px;font-family:'Roboto Slab',serif}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:800;font-size:14px;cursor:pointer;font-family:'Roboto Slab',serif}
        .modal-actions .btn-confirm{background:#7D02AB;color:#FFFFFF}
        .modal-actions .btn-cancel{background:#F5E6FF;color:#000000}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:800;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{background:rgba(16,185,129,0.2);color:#047857}
        .toast.error{background:rgba(239,68,68,0.2);color:#B91C1C}
    </style>
</head>
<body>
    <?php if (!$isAdminLoggedIn): ?>
    <div class="login-container">
        <div class="login-box">
            <h1>🔐 Admin Access</h1>
            <p>Zupeex Command Center</p>
            <?php if (isset($loginError)): ?><div class="login-error"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
            <form method="POST">
                <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                <button type="submit" name="admin_login" class="login-btn">Login</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="admin-container">
        <div class="admin-header">
            <h1>⚡ Admin Command Center</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="admin_users.php">👥 Users</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="deposits.php">💰 Deposits</a>
                <a href="disputes.php">📋 Disputes</a>
                <a href="tournaments.php">🏆 Tournaments</a>
                <a href="tickets.php">🎟️ Tickets</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card"><div class="stat-label">Loading...</div><div class="stat-value">...</div></div>
        </div>
        
        <div class="table-container">
            <table>
                <thead><tr><th>ID</th><th>Username</th><th>Mobile</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="usersBody"><tr><td colspan="6" style="text-align:center;padding:24px;color:#000000">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
    
    <div class="modal-overlay" id="balanceModal">
        <div class="modal-box">
            <h2>💰 Adjust Balance</h2>
            <input type="hidden" id="balUserId">
            <div class="form-group"><label>Amount</label><input type="number" id="balAmount" step="0.01"></div>
            <div class="form-group"><label>Type</label><select id="balType"><option value="credit">Credit</option><option value="debit">Debit</option></select></div>
            <div class="modal-actions"><button class="btn-confirm" onclick="submitBalance()">✅ Confirm</button><button class="btn-cancel" onclick="closeModal('balanceModal')">Cancel</button></div>
        </div>
    </div>
    <div class="toast" id="adminToast"></div>
    
    <script>
        let state = { csrfToken: '<?php echo $csrf_token; ?>' };
        
        // 🔥 CSRF Token Auto-Refresh
        async function refreshCsrfToken() {
            try {
                const resp = await fetch('?ajax=1&action=get_csrf', {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await resp.json();
                if (data.success && data.data && data.data.csrf_token) {
                    state.csrfToken = data.data.csrf_token;
                    return data.data.csrf_token;
                }
                return null;
            } catch (e) {
                console.warn('[CSRF] Refresh error:', e);
                return null;
            }
        }
        
        // 🔥 Universal POST with Auto CSRF
        async function adminPost(url, body = {}) {
            try {
                await refreshCsrfToken();
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': state.csrfToken
                    },
                    body: JSON.stringify({ ...body, csrf_token: state.csrfToken })
                });
                const data = await response.json();
                
                if (!data.success && data.data && data.data.refresh_needed) {
                    if (data.data.csrf_token) {
                        state.csrfToken = data.data.csrf_token;
                    }
                    const retryResponse = await fetch(url, {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': state.csrfToken
                        },
                        body: JSON.stringify({ ...body, csrf_token: state.csrfToken })
                    });
                    return await retryResponse.json();
                }
                return data;
            } catch (e) {
                console.error('[Admin POST] Error:', e);
                return { success: false, message: 'Network error' };
            }
        }
        
        function handleApiResponse(r) {
            if (r.status === 401) { showToast('Session expired', 'error'); setTimeout(() => location.href = 'index.php', 1500); throw new Error('Session expired'); }
            return r.json();
        }
        
        function showToast(m, t = 'info') { const toast = document.getElementById('adminToast'); toast.textContent = m; toast.className = 'toast ' + t + ' show'; clearTimeout(toast._timeout); toast._timeout = setTimeout(() => toast.classList.remove('show'), 4000); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        function escapeHtml(s) { if (s === null || s === undefined) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }
        
        document.addEventListener('DOMContentLoaded', function() {
            loadStats(); loadUsers();
            document.querySelectorAll('.modal-overlay').forEach(m => { m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); }); });
        });
        
        async function loadStats() {
            try {
                const resp = await fetch('?ajax=1&action=get_stats', { credentials: 'include' });
                const d = await handleApiResponse(resp);
                if (d.success) {
                    const s = d.data;
                    document.getElementById('statsGrid').innerHTML = `
                        <div class="stat-card"><div class="stat-label">Total Users</div><div class="stat-value">${s.total_users || 0}</div></div>
                        <div class="stat-card"><div class="stat-label">Active Users</div><div class="stat-value">${s.active_users || 0}</div></div>
                        <div class="stat-card"><div class="stat-label">New Today</div><div class="stat-value">${s.new_users_today || 0}</div></div>
                        <div class="stat-card"><div class="stat-label">Total Matches</div><div class="stat-value">${s.total_matches || 0}</div></div>
                        <div class="stat-card"><div class="stat-label">Pending KYC</div><div class="stat-value">${s.pending_kyc || 0}</div></div>
                        <div class="stat-card"><div class="stat-label">Pending Withdrawals</div><div class="stat-value">${s.pending_withdrawals || 0}</div></div>
                    `;
                }
            } catch (e) {}
        }
        
        async function loadUsers() {
            document.getElementById('usersBody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#000000">Loading...</td></tr>';
            try {
                const resp = await fetch('?ajax=1&action=get_users&offset=0&limit=50', { credentials: 'include' });
                const d = await handleApiResponse(resp);
                if (d.success) {
                    document.getElementById('usersBody').innerHTML = (d.data.users || []).map(u => `<tr><td>#${u.id}</td><td>${escapeHtml(u.username)}</td><td>${escapeHtml(u.mobile)}</td><td style="color:#7D02AB;font-weight:700">₹${parseFloat(u.wallet_balance).toFixed(2)}</td><td><span class="status-badge ${u.is_active ? 'success' : 'failed'}">${u.is_active ? 'Active' : 'Inactive'}</span></td><td><button class="btn-action primary" onclick="editBalance(${u.id})">💰</button><button class="btn-action danger" onclick="toggleUser(${u.id})">🔒</button></td></tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:24px;color:#000000">No users found</td></tr>';
                }
            } catch (e) {
                document.getElementById('usersBody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#DC2626">Error loading users</td></tr>';
            }
        }
        
        function editBalance(uid) {
            document.getElementById('balUserId').value = uid;
            document.getElementById('balAmount').value = '';
            document.getElementById('balType').value = 'credit';
            document.getElementById('balanceModal').classList.add('active');
        }
        
        async function submitBalance() {
            const uid = document.getElementById('balUserId').value;
            const amt = parseFloat(document.getElementById('balAmount').value);
            const type = document.getElementById('balType').value;
            if (!uid || !amt || amt <= 0 || !isFinite(amt)) { showToast('Enter a valid positive amount', 'error'); return; }
            
            const d = await adminPost('?ajax=1&action=update_balance', { user_id: parseInt(uid), amount: amt, type: type, reason: 'Admin adjustment' });
            if (d.success) { showToast('Balance updated!', 'success'); closeModal('balanceModal'); loadUsers(); }
            else showToast(d.message || 'Failed', 'error');
        }
        
        async function toggleUser(uid) {
            if (!confirm('Toggle user status?')) return;
            const d = await adminPost('?ajax=1&action=toggle_user', { user_id: uid });
            if (d.success) { showToast('Status updated', 'success'); loadUsers(); }
            else showToast(d.message || 'Failed', 'error');
        }
    </script>
    <?php endif; ?>
</body>
</html>