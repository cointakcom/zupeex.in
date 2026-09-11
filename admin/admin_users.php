<?php
/**
 * ======================================================
 * ADMIN USERS.PHP - User Management (CSRF FIXED)
 * Ludo Tournament Platform - Admin Users Dashboard
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
if (!validateAdminSession()) { session_destroy(); header('Location: index.php'); exit; }

$csrf_token = CSRFToken::generate();
$statusFilter = $_GET['status'] ?? 'all';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/admin') $basePath = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Roboto Slab',serif;background:#FFFCF8;color:#000000;min-height:100vh}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-bottom:1px solid rgba(125,2,171,0.1);margin-bottom:24px;flex-wrap:wrap;gap:12px;background:#7D02AB;border-radius:12px;padding:20px;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .admin-header h1{font-size:24px;font-weight:800;color:#FFFFFF}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions span{color:#FFFFFF;font-weight:700}
        .admin-header-actions a{color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.3);border-radius:8px}
        .admin-header-actions a:hover{background:rgba(255,255,255,0.15)}
        .admin-header-actions a.logout{color:#FF6B6B;border-color:rgba(255,100,100,0.4)}
        .stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
        .stat-card{background:#7D02AB;padding:16px 20px;border-radius:12px;border:1px solid rgba(125,2,171,0.1);text-align:center;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .stat-card .stat-number{font-size:24px;font-weight:800;color:#FFFFFF}
        .stat-card .stat-label{font-size:12px;color:#FFFFFF;margin-top:2px;font-weight:700}
        .filter-bar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
        .filter-btn{padding:8px 20px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-weight:700;font-size:13px;cursor:pointer;font-family:'Roboto Slab',serif}
        .filter-btn:hover{background:#F5E6FF;color:#7D02AB}
        .filter-btn.active{background:#7D02AB;color:#FFFFFF;border-color:#7D02AB}
        .filter-btn:disabled{opacity:0.5;cursor:not-allowed}
        .search-bar{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
        .search-bar input{flex:1;min-width:200px;padding:10px 14px;border:1px solid rgba(125,2,171,0.2);border-radius:10px;background:#FFFFFF;color:#000000;font-size:14px;font-family:'Roboto Slab',serif}
        .search-bar input:focus{outline:none;border-color:#7D02AB}
        .table-container{background:#FFFFFF;border-radius:14px;border:1px solid rgba(125,2,171,0.1);overflow-x:auto;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        table{width:100%;border-collapse:collapse;font-size:14px}
        table th{padding:12px 16px;text-align:left;color:#FFFFFF;background:#7D02AB;font-weight:800;font-size:12px;text-transform:uppercase;white-space:nowrap}
        table td{padding:12px 16px;border-bottom:1px solid rgba(125,2,171,0.05);white-space:nowrap;color:#000000}
        .status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
        .status-badge.success{background:rgba(16,185,129,0.15);color:#059669}
        .status-badge.failed{background:rgba(239,68,68,0.15);color:#DC2626}
        .status-badge.pending{background:rgba(245,158,11,0.15);color:#D97706}
        .btn-action{padding:6px 14px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Roboto Slab',serif;margin:0 2px}
        .btn-action.primary{background:rgba(59,130,246,0.2);color:#2563EB}
        .btn-action.info{background:rgba(125,2,171,0.15);color:#7D02AB}
        .btn-action.danger{background:rgba(239,68,68,0.2);color:#DC2626}
        .btn-action.success{background:rgba(16,185,129,0.2);color:#059669}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#FFFFFF;padding:32px;border-radius:16px;max-width:520px;width:100%;border:1px solid rgba(125,2,171,0.1);max-height:90vh;overflow-y:auto;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .modal-box h2{font-size:20px;font-weight:800;margin-bottom:16px;color:#000000}
        .form-group{margin-bottom:14px}.form-group label{display:block;font-size:13px;font-weight:700;color:#000000;margin-bottom:4px}
        .form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-size:14px;font-family:'Roboto Slab',serif}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:800;font-size:14px;cursor:pointer;font-family:'Roboto Slab',serif}
        .modal-actions .btn-confirm{background:#7D02AB;color:#FFFFFF}
        .modal-actions .btn-cancel{background:#F5E6FF;color:#000000}
        .txn-list{max-height:320px;overflow-y:auto;display:flex;flex-direction:column;gap:8px}
        .txn-row{display:flex;justify-content:space-between;background:#F5E6FF;padding:10px 12px;border-radius:8px;font-size:13px;color:#000000}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:800;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}.toast.success{background:rgba(16,185,129,0.2);color:#059669}.toast.error{background:rgba(239,68,68,0.2);color:#DC2626}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>👥 User Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a><a href="deposits.php">💰 Deposits</a>
                <a href="disputes.php">📋 Disputes</a>
                <a href="tournaments.php">🏆 Tournaments</a><a href="tickets.php">🎟️ Tickets</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>

        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statTotal">...</div><div class="stat-label">Total Users</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statActive">...</div><div class="stat-label">Active (30d)</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statNewToday">...</div><div class="stat-label">New Today</div></div>
        </div>

        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter==='all'?'active':''; ?>" data-status="">📋 All</button>
            <button class="filter-btn <?php echo $statusFilter==='active'?'active':''; ?>" data-status="1">🟢 Active</button>
            <button class="filter-btn <?php echo $statusFilter==='inactive'?'active':''; ?>" data-status="0">🔴 Inactive</button>
        </div>

        <div class="search-bar">
            <input type="text" id="userSearch" placeholder="Search by username, mobile, or email..." onkeyup="debounceSearch()">
            <button class="filter-btn" onclick="state.currentPage=0;loadUsers()">🔄 Refresh</button>
        </div>

        <div class="table-container">
            <table>
                <thead><tr><th>ID</th><th>Username</th><th>Mobile</th><th>Email</th><th>Balance</th><th>Matches (W)</th><th>Earnings</th><th>KYC</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                <tbody id="usersBody"><tr><td colspan="11" style="text-align:center;padding:24px;color:#000000">Loading...</td></tr></tbody>
            </table>
        </div>
        <div id="paginationBar" style="display:flex;justify-content:center;gap:12px;margin-top:20px"></div>
    </div>

    <!-- BALANCE ADJUST MODAL -->
    <div class="modal-overlay" id="balanceModal">
        <div class="modal-box">
            <h2>💰 Adjust Balance</h2>
            <p id="balanceUserLabel" style="color:#000000;font-size:13px;margin-bottom:12px"></p>
            <input type="hidden" id="balUserId">
            <div class="form-group"><label>Amount (₹)</label><input type="number" id="balAmount" step="0.01" min="0.01"></div>
            <div class="form-group"><label>Type</label><select id="balType"><option value="credit">Credit (Add funds)</option><option value="debit">Debit (Remove funds)</option></select></div>
            <div class="form-group"><label>Reason</label><input type="text" id="balReason" placeholder="Admin adjustment" value="Admin adjustment"></div>
            <div class="modal-actions"><button class="btn-confirm" onclick="submitBalance()">✅ Confirm</button><button class="btn-cancel" onclick="closeModal('balanceModal')">Cancel</button></div>
        </div>
    </div>

    <!-- TRANSACTIONS MODAL -->
    <div class="modal-overlay" id="txnModal">
        <div class="modal-box" style="max-width:600px">
            <h2>📜 Transaction History</h2>
            <div class="txn-list" id="txnList">Loading...</div>
            <div class="modal-actions"><button class="btn-cancel" onclick="closeModal('txnModal')">Close</button></div>
        </div>
    </div>

    <div class="toast" id="adminToast"></div>

    <script>
        const BASE_PATH = '<?php echo $basePath; ?>';
        const USERS_API = 'index.php';
        let state = {currentPage:0,limit:50,total:0,status:'<?php echo $statusFilter==="active"?"1":($statusFilter==="inactive"?"0":""); ?>',search:'',csrfToken:'<?php echo $csrf_token; ?>',searchTimeout:null};

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

        function handleApiResponse(r){if(r.status===401){showToast('Session expired','error');setTimeout(()=>location.href='index.php',1500);throw new Error('Session expired')}return r.json()}
        function showToast(m,t='info'){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';clearTimeout(toast._timeout);toast._timeout=setTimeout(()=>toast.classList.remove('show'),4000)}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        function escapeHtml(s){if(s===null||s===undefined)return'';const d=document.createElement('div');d.textContent=String(s);return d.innerHTML}

        document.addEventListener('DOMContentLoaded', function(){
            loadStats();loadUsers();
            document.querySelectorAll('.filter-btn[data-status]').forEach(btn=>{
                btn.addEventListener('click', function(){
                    document.querySelectorAll('.filter-btn[data-status]').forEach(b=>b.classList.remove('active'));
                    this.classList.add('active');
                    state.status=this.dataset.status;
                    state.currentPage=0;
                    loadUsers();
                });
            });
        });

        function loadStats(){
            fetch(`${USERS_API}?ajax=1&action=get_stats`, { credentials: 'include' }).then(handleApiResponse).then(d=>{
                if(d.success){
                    document.getElementById('statTotal').textContent=d.data.total_users||0;
                    document.getElementById('statActive').textContent=d.data.active_users||0;
                    document.getElementById('statNewToday').textContent=d.data.new_users_today||0;
                }
            }).catch(()=>{});
        }

        function loadUsers(){
            document.getElementById('usersBody').innerHTML='<tr><td colspan="11" style="text-align:center;padding:24px;color:#000000">Loading...</td></tr>';
            const offset=state.currentPage*state.limit;
            const activeParam = state.status ? `&active=${state.status}` : '';
            fetch(`${USERS_API}?ajax=1&action=get_users&offset=${offset}&limit=${state.limit}&search=${encodeURIComponent(state.search)}${activeParam}`, { credentials: 'include' }).then(handleApiResponse).then(d=>{
                if(d.success){
                    state.total=d.data.total||0;
                    document.getElementById('usersBody').innerHTML=(d.data.users||[]).map(u=>`<tr>
                        <td>#${u.id}</td>
                        <td>${escapeHtml(u.username)}</td>
                        <td>${escapeHtml(u.mobile)}</td>
                        <td>${escapeHtml(u.email||'-')}</td>
                        <td style="color:#7D02AB;font-weight:700">₹${parseFloat(u.wallet_balance).toFixed(2)}</td>
                        <td>${parseInt(u.total_matches_played||0)} (${parseInt(u.total_matches_won||0)})</td>
                        <td style="color:#059669;font-weight:700">₹${parseFloat(u.total_earnings||0).toFixed(2)}</td>
                        <td><span class="status-badge ${u.kyc_status==='verified'?'success':(u.kyc_status==='rejected'?'failed':'pending')}">${escapeHtml((u.kyc_status||'pending').toUpperCase())}</span></td>
                        <td><span class="status-badge ${u.is_active?'success':'failed'}">${u.is_active?'Active':'Inactive'}</span></td>
                        <td>${u.created_at ? new Date(u.created_at).toLocaleDateString() : '-'}</td>
                        <td>
                            <button class="btn-action primary" title="Adjust balance" onclick="editBalance(${u.id},'${escapeHtml(u.username)}')">💰</button>
                            <button class="btn-action info" title="Transaction history" onclick="viewTransactions(${u.id},'${escapeHtml(u.username)}')">📜</button>
                            <button class="btn-action ${u.is_active?'danger':'success'}" title="Toggle active status" onclick="toggleUser(${u.id})">${u.is_active?'🔒':'🔓'}</button>
                        </td>
                    </tr>`).join('') || '<tr><td colspan="11" style="text-align:center;padding:24px;color:#000000">No users found</td></tr>';
                    renderPagination();
                }
            }).catch(()=>{document.getElementById('usersBody').innerHTML='<tr><td colspan="11" style="text-align:center;padding:24px;color:#DC2626">Error loading users</td></tr>'});
        }

        function renderPagination(){
            const totalPages=Math.max(1,Math.ceil(state.total/state.limit));
            const bar=document.getElementById('paginationBar');
            if(state.total<=state.limit){bar.innerHTML='';return}
            bar.innerHTML=`<button class="filter-btn" ${state.currentPage<=0?'disabled':''} onclick="changePage(-1)">← Prev</button><span style="align-self:center;color:#000000;font-size:13px">Page ${state.currentPage+1} of ${totalPages} (${state.total} users)</span><button class="filter-btn" ${state.currentPage>=totalPages-1?'disabled':''} onclick="changePage(1)">Next →</button>`;
        }
        function changePage(delta){const totalPages=Math.max(1,Math.ceil(state.total/state.limit));state.currentPage=Math.min(Math.max(0,state.currentPage+delta),totalPages-1);loadUsers()}
        function debounceSearch(){clearTimeout(state.searchTimeout);state.searchTimeout=setTimeout(()=>{state.search=document.getElementById('userSearch').value;state.currentPage=0;loadUsers()},400)}

        function editBalance(uid, uname){
            document.getElementById('balUserId').value=uid;
            document.getElementById('balanceUserLabel').textContent=`User: ${uname} (#${uid})`;
            document.getElementById('balAmount').value='';
            document.getElementById('balType').value='credit';
            document.getElementById('balReason').value='Admin adjustment';
            document.getElementById('balanceModal').classList.add('active');
        }

        async function submitBalance(){
            const uid=document.getElementById('balUserId').value;
            const amt=parseFloat(document.getElementById('balAmount').value);
            const type=document.getElementById('balType').value;
            const reason=document.getElementById('balReason').value.trim()||'Admin adjustment';
            if(!uid||!amt||amt<=0||!isFinite(amt)){showToast('Enter a valid positive amount','error');return}
            
            const d = await adminPost(`${USERS_API}?ajax=1&action=update_balance`, {
                user_id: parseInt(uid),
                amount: amt,
                type: type,
                reason: reason
            });
            
            if(d.success){showToast('Balance updated!','success');closeModal('balanceModal');loadUsers()}
            else showToast(d.message||'Failed','error');
        }

        async function toggleUser(uid){
            if(!confirm('Toggle this user\'s active status?'))return;
            
            const d = await adminPost(`${USERS_API}?ajax=1&action=toggle_user`, {
                user_id: uid
            });
            
            if(d.success){showToast('Status updated','success');loadUsers()}
            else showToast(d.message||'Failed','error');
        }

        function viewTransactions(uid, uname){
            document.getElementById('txnList').innerHTML='Loading...';
            document.getElementById('txnModal').classList.add('active');
            fetch(`${USERS_API}?ajax=1&action=get_transactions&user_id=${uid}&limit=30`, { credentials: 'include' }).then(handleApiResponse).then(d=>{
                if(d.success){
                    const rows=(d.data||[]);
                    document.getElementById('txnList').innerHTML = rows.length ? rows.map(t=>`<div class="txn-row"><span>${escapeHtml(t.description||t.source)}<br><small style="color:#000000">${t.created_at ? new Date(t.created_at).toLocaleString() : ''}</small></span><span style="color:${t.type==='credit'?'#059669':'#DC2626'};font-weight:800">${t.type==='credit'?'+':'-'}₹${parseFloat(t.amount).toFixed(2)}</span></div>`).join('') : '<div style="text-align:center;color:#000000;padding:20px">No transactions found</div>';
                } else {
                    document.getElementById('txnList').innerHTML='<div style="text-align:center;color:#DC2626;padding:20px">Failed to load transactions</div>';
                }
            }).catch(()=>{document.getElementById('txnList').innerHTML='<div style="text-align:center;color:#DC2626;padding:20px">Error loading transactions</div>'});
        }
    </script>
</body>
</html>