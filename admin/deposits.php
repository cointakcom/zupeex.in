<?php
/**
 * ADMIN DEPOSITS.PHP - Deposit Listing Dashboard
 * Version: 1.1.0 - ROOT DEPLOYMENT FIX
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
// FIX: Root deployment — no ludo_project folder
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/admin') $basePath = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposits - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Roboto Slab',serif;background:#FFFCF8;color:#000000;min-height:100vh}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#7D02AB;border-radius:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:800;color:#FFFFFF}
        .admin-header-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .admin-header-actions span{color:#FFFFFF;font-weight:700}
        .admin-header-actions a{color:#FFFFFF;text-decoration:none;font-weight:700;font-size:13px;padding:8px 14px;border:1px solid rgba(255,255,255,0.3);border-radius:8px}
        .admin-header-actions a:hover{background:rgba(255,255,255,0.15)}
        .admin-header-actions a.logout{color:#FF6B6B;border-color:rgba(255,100,100,0.4)}
        .stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
        .stat-card{background:#7D02AB;padding:16px 20px;border-radius:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);text-align:center}
        .stat-card .stat-number{font-size:24px;font-weight:800;color:#FFFFFF}
        .stat-card .stat-label{font-size:12px;color:#FFFFFF;margin-top:2px;font-weight:700}
        .filter-bar{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
        .search-bar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
        .search-bar input[type=text]{flex:1;min-width:200px;padding:10px 14px;border-radius:8px;border:1px solid rgba(125,2,171,0.2);background:#FFFFFF;color:#000000;font-size:13px;font-family:'Roboto Slab',serif}
        .search-bar input[type=date]{padding:10px 12px;border-radius:8px;border:1px solid rgba(125,2,171,0.2);background:#FFFFFF;color:#000000;font-size:13px;font-family:'Roboto Slab',serif}
        .filter-btn{padding:8px 20px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-weight:700;font-size:13px;cursor:pointer;font-family:'Roboto Slab',serif}
        .filter-btn:hover{background:#F5E6FF;color:#7D02AB}
        .filter-btn.active{background:#7D02AB;color:#FFFFFF;border-color:#7D02AB}
        .withdrawal-list{display:grid;gap:16px}
        .withdrawal-card{background:#FFFFFF;border-radius:14px;padding:20px;border:1px solid rgba(125,2,171,0.1);box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .withdrawal-card .wd-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:8px}
        .withdrawal-card .user-name{font-size:18px;font-weight:800;color:#000000}
        .withdrawal-card .user-detail{font-size:13px;color:#555555}
        .status-badge{padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700}
        .status-badge.pending{background:rgba(245,158,11,0.15);color:#B45309}
        .status-badge.success{background:rgba(16,185,129,0.15);color:#047857}
        .status-badge.failed{background:rgba(239,68,68,0.15);color:#B91C1C}
        .wd-details{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:12px 0}
        .detail-item{background:#F5E6FF;padding:8px 12px;border-radius:8px}
        .detail-item .label{font-size:11px;color:#555555;text-transform:uppercase;font-weight:700}
        .detail-item .value{font-size:14px;font-weight:800;color:#000000}
        .detail-item .value.amount{color:#7D02AB;font-size:18px}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>💰 Deposit Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a><a href="admin_users.php">👥 Users</a><a href="withdrawals.php">🏦 Withdrawals</a><a href="tickets.php">🎟️ Tickets</a><a href="settings.php">⚙️ Settings</a><a href="kyc.php">🛡️ KYC</a><a href="disputes.php">📋 Disputes</a><a href="tournaments.php">🏆 Tournaments</a><a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statPending">...</div><div class="stat-label">Pending</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statSuccess">...</div><div class="stat-label">Success</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statFailed">...</div><div class="stat-label">Failed</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statTodayAmount">...</div><div class="stat-label">Today's ₹</div></div>
        </div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="🔍 Search by username, mobile, or order ID..." oninput="debouncedSearch()">
            <input type="date" id="dateFrom" onchange="loadDeposits()" title="From date">
            <input type="date" id="dateTo" onchange="loadDeposits()" title="To date">
        </div>
        <div class="filter-bar">
            <button class="filter-btn active" data-status="success">✅ Success</button>
            <button class="filter-btn" data-status="pending">⏳ Pending</button>
            <button class="filter-btn" data-status="failed">❌ Failed</button>
            <button class="filter-btn" data-status="all">📋 All</button>
        </div>
        <div class="withdrawal-list" id="depositList"><div style="text-align:center;padding:40px;color:#000000">Loading...</div></div>
        <div id="paginationBar" style="display:flex;justify-content:center;gap:12px;margin-top:20px"></div>
    </div>
    <script>
        let state={currentPage:0,limit:50,total:0,status:'success'};
        function handleApiResponse(r){if(r.status===401){setTimeout(()=>location.href='index.php',1000);throw new Error('Session expired')}return r.json()}
        document.addEventListener('DOMContentLoaded',function(){loadStats();loadDeposits();document.querySelectorAll('.filter-btn').forEach(btn=>{btn.addEventListener('click',function(){document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');state.status=this.dataset.status;state.currentPage=0;loadDeposits()})})});
        function loadStats(){fetch('<?php echo $basePath; ?>/api/admin_deposits.php?action=get_stats').then(handleApiResponse).then(d=>{if(d.success){document.getElementById('statPending').textContent=d.data.pending||0;document.getElementById('statSuccess').textContent=d.data.success||0;document.getElementById('statFailed').textContent=d.data.failed||0;document.getElementById('statTodayAmount').textContent='₹'+parseFloat(d.data.today_amount||0).toFixed(0)}}).catch(()=>{})}
        let searchDebounceTimer = null;
        function debouncedSearch() { clearTimeout(searchDebounceTimer); searchDebounceTimer = setTimeout(() => { state.currentPage = 0; loadDeposits(); }, 400); }

        function loadDeposits(){
            document.getElementById('depositList').innerHTML='<div style="text-align:center;padding:40px;color:#000000">Loading...</div>';
            const status=state.status==='all'?'':state.status;
            const offset=state.currentPage*state.limit;
            const search=encodeURIComponent(document.getElementById('searchInput')?.value.trim()||'');
            const dateFrom=document.getElementById('dateFrom')?.value||'';
            const dateTo=document.getElementById('dateTo')?.value||'';
            fetch(`<?php echo $basePath; ?>/api/admin_deposits.php?action=list&status=${status}&offset=${offset}&limit=${state.limit}&search=${search}&date_from=${dateFrom}&date_to=${dateTo}`).then(handleApiResponse).then(d=>{
                if(d.success){
                    state.total=d.data.total||0;
                    document.getElementById('depositList').innerHTML=(d.data.deposits||[]).map(dep=>`<div class="withdrawal-card"><div class="wd-header"><div><span class="user-name">${escapeHtml(dep.username||'Unknown')}</span><br><span class="user-detail">📱 ${escapeHtml(dep.mobile||'N/A')} • User #${dep.user_id} • via ${escapeHtml(dep.payment_gateway||'N/A')}</span></div><span class="status-badge ${dep.status}">${escapeHtml(dep.status.toUpperCase())}</span></div><div class="wd-details"><div class="detail-item"><div class="label">Amount</div><div class="value amount">₹${parseFloat(dep.amount).toFixed(2)}</div></div><div class="detail-item"><div class="label">Order ID</div><div class="value">${escapeHtml(dep.order_id||'N/A')}</div></div><div class="detail-item"><div class="label">Gateway Txn ID</div><div class="value">${escapeHtml(dep.gateway_transaction_id||'—')}</div></div><div class="detail-item"><div class="label">Date</div><div class="value">${escapeHtml(dep.created_at||'')}</div></div></div></div>`).join('')||'<div style="text-align:center;padding:40px;color:#000000">No deposits</div>';
                    renderPagination();
                }
            }).catch(()=>{document.getElementById('depositList').innerHTML='<div style="text-align:center;padding:40px;color:#DC2626">Error</div>'})
        }
        function renderPagination(){const totalPages=Math.max(1,Math.ceil(state.total/state.limit));const bar=document.getElementById('paginationBar');if(state.total<=state.limit){bar.innerHTML='';return}bar.innerHTML=`<button class="filter-btn" ${state.currentPage<=0?'disabled':''} onclick="changePage(-1)">← Prev</button><span style="align-self:center;color:#000000;font-size:13px;font-weight:700">Page ${state.currentPage+1} of ${totalPages}</span><button class="filter-btn" ${state.currentPage>=totalPages-1?'disabled':''} onclick="changePage(1)">Next →</button>`}
        function changePage(delta){const totalPages=Math.max(1,Math.ceil(state.total/state.limit));state.currentPage=Math.min(Math.max(0,state.currentPage+delta),totalPages-1);loadDeposits()}
        function escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML}
    </script>
</body>
</html>