<?php
/**
 * ======================================================
 * ADMIN WITHDRAWALS.PHP - Withdrawal Management (CSRF FIXED)
 * Ludo Tournament Platform - Admin Withdrawal Dashboard
 * Version: 3.2.0 - CSRF AUTO-REFRESH FIX
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
$statusFilter = $_GET['status'] ?? 'pending';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/admin') $basePath = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawals - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Roboto Slab',serif;background:#FFFCF8;color:#000000;min-height:100vh}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#7D02AB;border-radius:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:800;color:#FFFFFF}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions span{color:#FFFFFF;font-weight:700}
        .admin-header-actions a{color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.3);border-radius:8px}
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
        .status-badge.processing{background:rgba(59,130,246,0.15);color:#2563EB}
        .status-badge.approved{background:rgba(125,2,171,0.15);color:#7D02AB}
        .status-badge.completed{background:rgba(16,185,129,0.15);color:#047857}
        .status-badge.rejected{background:rgba(239,68,68,0.15);color:#B91C1C}
        .wd-details{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:12px 0}
        .detail-item{background:#F5E6FF;padding:8px 12px;border-radius:8px}
        .detail-item .label{font-size:11px;color:#555555;text-transform:uppercase;font-weight:700}
        .detail-item .value{font-size:14px;font-weight:800;color:#000000}
        .detail-item .value.amount{color:#7D02AB;font-size:18px}
        .action-buttons{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap}
        .btn-action{padding:8px 20px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;font-family:'Roboto Slab',serif}
        .btn-action.approve{background:rgba(16,185,129,0.15);color:#047857}
        .btn-action.reject{background:rgba(239,68,68,0.15);color:#B91C1C}
        .btn-action.process{background:rgba(59,130,246,0.15);color:#2563EB}
        .btn-action.complete{background:rgba(16,185,129,0.15);color:#047857}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#FFFFFF;padding:32px;border-radius:16px;max-width:500px;width:100%;border:1px solid rgba(125,2,171,0.1);box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .modal-box h2{font-size:20px;font-weight:800;margin-bottom:16px;color:#000000}
        .form-group{margin-bottom:14px}.form-group label{display:block;font-size:13px;font-weight:700;color:#000000;margin-bottom:4px}
        .form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-size:14px;font-family:'Roboto Slab',serif;min-height:100px;resize:vertical}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:800;font-size:14px;cursor:pointer;font-family:'Roboto Slab',serif}
        .modal-actions .btn-danger{background:rgba(239,68,68,0.2);color:#B91C1C}
        .modal-actions .btn-cancel{background:#F5E6FF;color:#000000}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:800;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}.toast.success{background:rgba(16,185,129,0.2);color:#047857}.toast.error{background:rgba(239,68,68,0.2);color:#B91C1C}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>🏦 Withdrawal Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a><a href="admin_users.php">👥 Users</a><a href="deposits.php">💰 Deposits</a><a href="settings.php">⚙️ Settings</a><a href="kyc.php">🛡️ KYC</a><a href="disputes.php">📋 Disputes</a><a href="tournaments.php">🏆 Tournaments</a><a href="tickets.php">🎟️ Tickets</a><a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statPending">...</div><div class="stat-label">Pending</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statProcessing">...</div><div class="stat-label">Processing</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statCompleted">...</div><div class="stat-label">Completed</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statRejected">...</div><div class="stat-label">Rejected</div></div>
        </div>
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="🔍 Search by username or mobile..." oninput="debouncedSearch()">
            <input type="date" id="dateFrom" onchange="loadWithdrawals()" title="From date">
            <input type="date" id="dateTo" onchange="loadWithdrawals()" title="To date">
        </div>
        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter==='pending'?'active':''; ?>" data-status="pending">⏳ Pending</button>
            <button class="filter-btn <?php echo $statusFilter==='processing'?'active':''; ?>" data-status="processing">🔄 Processing</button>
            <button class="filter-btn <?php echo $statusFilter==='approved'?'active':''; ?>" data-status="approved">✅ Approved</button>
            <button class="filter-btn <?php echo $statusFilter==='completed'?'active':''; ?>" data-status="completed">✔️ Completed</button>
            <button class="filter-btn <?php echo $statusFilter==='rejected'?'active':''; ?>" data-status="rejected">❌ Rejected</button>
            <button class="filter-btn <?php echo $statusFilter==='all'?'active':''; ?>" data-status="all">📋 All</button>
        </div>
        <div class="withdrawal-list" id="withdrawalList"><div style="text-align:center;padding:40px;color:#000000">Loading...</div></div>
        <div id="paginationBar" style="display:flex;justify-content:center;gap:12px;margin-top:20px"></div>
    </div>
    <div class="modal-overlay" id="rejectModal"><div class="modal-box"><h2>❌ Reject Withdrawal</h2><input type="hidden" id="rejectWdId"><div class="form-group"><label>Reason</label><textarea id="rejectReason"></textarea></div><div class="modal-actions"><button class="btn-danger" onclick="confirmReject()">❌ Reject</button><button class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button></div></div></div>
    <div class="toast" id="adminToast"></div>
    <script>
        let state={currentPage:0,limit:50,total:0,status:'<?php echo $statusFilter; ?>',csrfToken:'<?php echo $csrf_token; ?>'};
        
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
        document.addEventListener('DOMContentLoaded',function(){loadStats();loadWithdrawals();document.querySelectorAll('.filter-btn').forEach(btn=>{btn.addEventListener('click',function(){document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');state.status=this.dataset.status;state.currentPage=0;loadWithdrawals()})})});
        function loadStats(){fetch('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=get_stats', { credentials: 'include' }).then(handleApiResponse).then(d=>{if(d.success){document.getElementById('statPending').textContent=d.data.pending||0;document.getElementById('statProcessing').textContent=d.data.processing||0;document.getElementById('statCompleted').textContent=d.data.completed||0;document.getElementById('statRejected').textContent=d.data.rejected||0}}).catch(()=>{})}
        let searchDebounceTimer = null;
        function debouncedSearch() { clearTimeout(searchDebounceTimer); searchDebounceTimer = setTimeout(() => { state.currentPage = 0; loadWithdrawals(); }, 400); }

        function loadWithdrawals(){
            document.getElementById('withdrawalList').innerHTML='<div style="text-align:center;padding:40px;color:#000000">Loading...</div>';
            const status=state.status==='all'?'':state.status;
            const offset=state.currentPage*state.limit;
            const search=encodeURIComponent(document.getElementById('searchInput')?.value.trim()||'');
            const dateFrom=document.getElementById('dateFrom')?.value||'';
            const dateTo=document.getElementById('dateTo')?.value||'';
            fetch(`<?php echo $basePath; ?>/api/admin_withdrawals.php?action=list&status=${status}&offset=${offset}&limit=${state.limit}&search=${search}&date_from=${dateFrom}&date_to=${dateTo}`, { credentials: 'include' }).then(handleApiResponse).then(d=>{
                if(d.success){
                    state.total=d.data.total||0;
                    document.getElementById('withdrawalList').innerHTML=(d.data.withdrawals||[]).map(w=>{
                        const payoutInfo = w.upi_id ? `UPI: ${escapeHtml(w.upi_id)}` : `${escapeHtml(w.bank_account_name||'')} • A/C ${escapeHtml(w.bank_account_number||'N/A')} • IFSC ${escapeHtml(w.bank_ifsc||'N/A')}`;
                        return `<div class="withdrawal-card"><div class="wd-header"><div><span class="user-name">${escapeHtml(w.username||'Unknown')}</span><br><span class="user-detail">📱 ${escapeHtml(w.mobile||'N/A')} • User #${w.user_id}</span><br><span class="user-detail">🏦 ${payoutInfo}</span></div><span class="status-badge ${w.status}">${escapeHtml(w.status.toUpperCase())}</span></div><div class="wd-details"><div class="detail-item"><div class="label">Amount</div><div class="value amount">₹${parseFloat(w.amount).toFixed(2)}</div></div><div class="detail-item"><div class="label">Transaction ID</div><div class="value">${escapeHtml(w.transaction_id||'N/A')}</div></div><div class="detail-item"><div class="label">KYC Status</div><div class="value">${escapeHtml(w.kyc_status||'N/A')}</div></div><div class="detail-item"><div class="label">Requested</div><div class="value">${escapeHtml(w.created_at||'')}</div></div></div><div class="action-buttons">${w.status==='pending'?`<button class="btn-action approve" onclick="approveWithdrawal(${w.id})">✅ Approve</button><button class="btn-action reject" onclick="openRejectModal(${w.id})">❌ Reject</button>`:''}${w.status==='approved'?`<button class="btn-action process" onclick="processWithdrawal(${w.id})">🔄 Process</button>`:''}${w.status==='processing'?`<button class="btn-action complete" onclick="completeWithdrawal(${w.id})">✅ Complete</button>`:''}</div></div>`;
                    }).join('')||'<div style="text-align:center;padding:40px;color:#000000">No withdrawals</div>';
                    renderPagination();
                }
            }).catch(()=>{document.getElementById('withdrawalList').innerHTML='<div style="text-align:center;padding:40px;color:#DC2626">Error</div>'})
        }
        function renderPagination(){
            const totalPages=Math.max(1,Math.ceil(state.total/state.limit));
            const bar=document.getElementById('paginationBar');
            if(state.total<=state.limit){bar.innerHTML='';return}
            bar.innerHTML=`<button class="filter-btn" ${state.currentPage<=0?'disabled':''} onclick="changePage(-1)">← Prev</button><span style="align-self:center;color:#000000;font-size:13px;font-weight:700">Page ${state.currentPage+1} of ${totalPages}</span><button class="filter-btn" ${state.currentPage>=totalPages-1?'disabled':''} onclick="changePage(1)">Next →</button>`;
        }
        function changePage(delta){const totalPages=Math.max(1,Math.ceil(state.total/state.limit));state.currentPage=Math.min(Math.max(0,state.currentPage+delta),totalPages-1);loadWithdrawals()}
        
        async function approveWithdrawal(id){
            if(!confirm('Approve this withdrawal?'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=approve', { id: id });
            if(d.success){showToast('Approved!','success');loadStats();loadWithdrawals()}
            else showToast(d.message||'Failed','error');
        }
        
        function openRejectModal(id){document.getElementById('rejectWdId').value=id;document.getElementById('rejectReason').value='';document.getElementById('rejectModal').classList.add('active')}
        
        async function confirmReject(){
            const id=document.getElementById('rejectWdId').value;
            const reason=document.getElementById('rejectReason').value.trim();
            if(!reason||reason.length<10){showToast('Reason 10+ chars','error');return}
            const d = await adminPost('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=reject', { id: parseInt(id), reason: reason });
            if(d.success){showToast('Rejected & refunded','success');closeModal('rejectModal');loadStats();loadWithdrawals()}
            else showToast(d.message||'Failed','error');
        }
        
        async function processWithdrawal(id){
            if(!confirm('Mark as processing?'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=process', { id: id });
            if(d.success){showToast('Processing','success');loadWithdrawals()}
            else showToast(d.message||'Failed','error');
        }
        
        async function completeWithdrawal(id){
            if(!confirm('Mark as completed?'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=complete', { id: id });
            if(d.success){showToast('Completed!','success');loadWithdrawals()}
            else showToast(d.message||'Failed','error');
        }
        
        function escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML}
    </script>
</body>
</html>