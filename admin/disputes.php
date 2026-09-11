<?php
/**
 * ======================================================
 * ADMIN DISPUTES.PHP - Dispute Management UI (CSRF FIXED)
 * Ludo Tournament Platform - Admin Dispute Dashboard
 * Version: 3.2.0 - CSRF AUTO-REFRESH FIX
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

SessionManager::init();

function validateAdminSession() {
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) return false;
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}

if (!validateAdminSession()) { session_destroy(); header('Location: index.php'); exit; }

$csrf_token = CSRFToken::generate();
$statusFilter = $_GET['status'] ?? 'open';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/admin') $basePath = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispute Management - Admin</title>
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
        .filter-btn{padding:8px 20px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-weight:700;font-size:13px;cursor:pointer;font-family:'Roboto Slab',serif}
        .filter-btn:hover{background:#F5E6FF;color:#7D02AB}
        .filter-btn.active{background:#7D02AB;color:#FFFFFF;border-color:#7D02AB}
        .ticket-list{display:grid;gap:16px}
        .ticket-card{background:#FFFFFF;border-radius:14px;padding:20px;border:1px solid rgba(125,2,171,0.1);border-left:4px solid #7D02AB;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .ticket-card.priority-urgent{border-left-color:#DC2626}
        .ticket-card.priority-high{border-left-color:#D97706}
        .ticket-card .ticket-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:8px}
        .ticket-card .ticket-subject{font-size:16px;font-weight:800;color:#000000}
        .ticket-card .ticket-meta{font-size:13px;color:#555555}
        .status-badge{padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700}
        .status-badge.open{background:rgba(239,68,68,0.15);color:#B91C1C}
        .status-badge.investigating{background:rgba(245,158,11,0.15);color:#B45309}
        .status-badge.resolved{background:rgba(16,185,129,0.15);color:#047857}
        .priority-badge{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700}
        .priority-badge.urgent{background:rgba(239,68,68,0.2);color:#B91C1C}
        .priority-badge.high{background:rgba(245,158,11,0.2);color:#B45309}
        .action-buttons{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap}
        .btn-action{padding:8px 20px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;font-family:'Roboto Slab',serif}
        .btn-action.investigate{background:rgba(245,158,11,0.15);color:#B45309}
        .btn-action.resolve{background:rgba(16,185,129,0.15);color:#047857}
        .btn-action.close{background:rgba(148,163,184,0.15);color:#555555}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#FFFFFF;padding:32px;border-radius:16px;max-width:600px;width:100%;border:1px solid rgba(125,2,171,0.1);max-height:90vh;overflow-y:auto;box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .modal-box h2{font-size:20px;font-weight:800;margin-bottom:16px;color:#000000}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#000000;margin-bottom:4px}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-size:14px;font-family:'Roboto Slab',serif}
        .form-group select option{background:#FFFFFF;color:#000000}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:800;font-size:14px;cursor:pointer;font-family:'Roboto Slab',serif}
        .modal-actions .btn-confirm{background:#7D02AB;color:#FFFFFF}
        .modal-actions .btn-cancel{background:#F5E6FF;color:#000000}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:800;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{background:rgba(16,185,129,0.2);color:#047857}
        .toast.error{background:rgba(239,68,68,0.2);color:#B91C1C}
        @media(max-width:768px){.stats-bar{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>📋 Dispute Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a>
                <a href="admin_users.php">👥 Users</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a><a href="deposits.php">💰 Deposits</a>
                <a href="tournaments.php">🏆 Tournaments</a><a href="tickets.php">🎟️ Tickets</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statOpen">...</div><div class="stat-label">Open</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statInvestigating">...</div><div class="stat-label">Investigating</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statResolved">...</div><div class="stat-label">Resolved</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#FFFFFF" id="statClosed">...</div><div class="stat-label">Closed</div></div>
        </div>
        
        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter==='open'?'active':''; ?>" data-status="open">🟡 Open</button>
            <button class="filter-btn <?php echo $statusFilter==='investigating'?'active':''; ?>" data-status="investigating">🔍 Investigating</button>
            <button class="filter-btn <?php echo $statusFilter==='resolved'?'active':''; ?>" data-status="resolved">✅ Resolved</button>
            <button class="filter-btn <?php echo $statusFilter==='closed'?'active':''; ?>" data-status="closed">🔒 Closed</button>
            <button class="filter-btn <?php echo $statusFilter==='all'?'active':''; ?>" data-status="all">📋 All</button>
        </div>
        
        <div class="ticket-list" id="ticketList"><div style="text-align:center;padding:40px;color:#000000">Loading...</div></div>
        <div id="paginationBar" style="display:flex;justify-content:center;gap:12px;margin-top:20px"></div>
    </div>
    
    <div class="modal-overlay" id="resolveModal">
        <div class="modal-box">
            <h2>✅ Resolve Ticket</h2>
            <input type="hidden" id="resolveTicketId">
            <div class="form-group"><label>Resolution Type</label><select id="resolutionType"><option value="winner_declared">🏆 Declare Winner</option><option value="refund">💰 Refund</option><option value="cancelled">❌ Cancel</option><option value="no_action">⏭️ No Action</option></select></div>
            <div class="form-group" id="winnerField"><label>Winner User ID</label><input type="number" id="winnerId"></div>
            <div class="form-group" id="refundField" style="display:none"><label>Refund Amount (₹)</label><input type="number" id="refundAmount" step="0.01"></div>
            <div class="form-group"><label>Notes</label><textarea id="resolutionNotes"></textarea></div>
            <div class="modal-actions"><button class="btn-confirm" onclick="confirmResolve()">✅ Resolve</button><button class="btn-cancel" onclick="closeModal('resolveModal')">Cancel</button></div>
        </div>
    </div>
    
    <div class="toast" id="adminToast"></div>
    
    <script>
        let state = {currentPage:0,limit:50,total:0,status:'<?php echo $statusFilter; ?>',csrfToken:'<?php echo $csrf_token; ?>'};
        
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
        
        document.addEventListener('DOMContentLoaded',function(){
            loadStats();loadTickets();
            document.querySelectorAll('.filter-btn').forEach(btn=>{btn.addEventListener('click',function(){document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');state.status=this.dataset.status;state.currentPage=0;loadTickets()})});
            document.getElementById('resolutionType').addEventListener('change',function(){document.getElementById('winnerField').style.display=this.value==='winner_declared'?'block':'none';document.getElementById('refundField').style.display=this.value==='refund'?'block':'none'});
        });
        
        function loadStats(){
            fetch('<?php echo $basePath; ?>/api/admin_disputes.php?action=get_stats', { credentials: 'include' }).then(handleApiResponse).then(d=>{
                if(d.success){document.getElementById('statOpen').textContent=d.data.open||0;document.getElementById('statInvestigating').textContent=d.data.investigating||0;document.getElementById('statResolved').textContent=d.data.resolved||0;document.getElementById('statClosed').textContent=d.data.closed||0}
            }).catch(()=>{});
        }
        
        function loadTickets(){
            document.getElementById('ticketList').innerHTML='<div style="text-align:center;padding:40px;color:#000000">Loading...</div>';
            const status=state.status==='all'?'':state.status;
            const offset=state.currentPage*state.limit;
            fetch(`<?php echo $basePath; ?>/api/admin_disputes.php?action=list&status=${status}&offset=${offset}&limit=${state.limit}`, { credentials: 'include' }).then(handleApiResponse).then(d=>{
                if(d.success){
                    state.total=d.data.total||0;
                    document.getElementById('ticketList').innerHTML=(d.data.tickets||[]).map(t=>`<div class="ticket-card priority-${t.priority}">
                        <div class="ticket-header"><div><span class="ticket-subject">#${escapeHtml(t.ticket_number)} - ${escapeHtml(t.subject)}</span><br><span class="ticket-meta">👤 ${escapeHtml(t.user_name||'Unknown')} • Room: ${escapeHtml(t.room_code||'N/A')} • ₹${parseFloat(t.entry_fee||0).toFixed(2)}</span></div><div style="display:flex;gap:8px"><span class="priority-badge ${t.priority}">${escapeHtml(t.priority.toUpperCase())}</span><span class="status-badge ${t.status}">${escapeHtml(t.status.toUpperCase())}</span></div></div>
                        <div class="action-buttons">${t.status==='open'?`<button class="btn-action investigate" onclick="investigateTicket(${t.id})">🔍 Investigate</button>`:''}${['open','investigating'].includes(t.status)?`<button class="btn-action resolve" onclick="openResolveModal(${t.id})">✅ Resolve</button>`:''}${t.status==='resolved'?`<button class="btn-action close" onclick="closeTicket(${t.id})">🔒 Close</button>`:''}</div></div>`).join('')||'<div style="text-align:center;padding:40px;color:#000000">No tickets</div>';
                    renderPagination();
                }
            }).catch(()=>{document.getElementById('ticketList').innerHTML='<div style="text-align:center;padding:40px;color:#DC2626">Error</div>'});
        }
        function renderPagination(){
            const totalPages=Math.max(1,Math.ceil(state.total/state.limit));
            const bar=document.getElementById('paginationBar');
            if(state.total<=state.limit){bar.innerHTML='';return}
            bar.innerHTML=`<button class="filter-btn" ${state.currentPage<=0?'disabled':''} onclick="changePage(-1)">← Prev</button><span style="align-self:center;color:#000000;font-size:13px;font-weight:700">Page ${state.currentPage+1} of ${totalPages}</span><button class="filter-btn" ${state.currentPage>=totalPages-1?'disabled':''} onclick="changePage(1)">Next →</button>`;
        }
        function changePage(delta){const totalPages=Math.max(1,Math.ceil(state.total/state.limit));state.currentPage=Math.min(Math.max(0,state.currentPage+delta),totalPages-1);loadTickets()}
        
        async function investigateTicket(id){
            if(!confirm('Mark as investigating?'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/admin_disputes.php?action=investigate', { id: id });
            if(d.success){showToast('Investigating','success');loadTickets();loadStats()}
            else showToast(d.message||'Failed','error');
        }
        
        function openResolveModal(id){document.getElementById('resolveTicketId').value=id;document.getElementById('resolutionType').value='no_action';document.getElementById('winnerId').value='';document.getElementById('refundAmount').value='';document.getElementById('resolutionNotes').value='';document.getElementById('winnerField').style.display='none';document.getElementById('refundField').style.display='none';document.getElementById('resolveModal').classList.add('active')}
        
        async function confirmResolve(){
            const id=document.getElementById('resolveTicketId').value;
            const type=document.getElementById('resolutionType').value;
            const notes=document.getElementById('resolutionNotes').value.trim();
            if(!notes||notes.length<5){showToast('Please add a short resolution note','error');return}
            const payload={id:parseInt(id),resolution_type:type,resolution_notes:notes};
            if(type==='winner_declared'){
                const wid=parseInt(document.getElementById('winnerId').value);
                if(!wid||wid<=0){showToast('Enter a valid winner user ID','error');return}
                payload.winner_id=wid;
            }
            if(type==='refund'){
                const ramt=parseFloat(document.getElementById('refundAmount').value);
                if(!ramt||ramt<=0||!isFinite(ramt)){showToast('Enter a valid refund amount','error');return}
                payload.refund_amount=ramt;
            }
            const d = await adminPost('<?php echo $basePath; ?>/api/admin_disputes.php?action=resolve', payload);
            if(d.success){showToast('Resolved!','success');closeModal('resolveModal');loadTickets();loadStats()}
            else showToast(d.message||'Failed','error');
        }
        
        async function closeTicket(id){
            if(!confirm('Close this ticket?'))return;
            const d = await adminPost('<?php echo $basePath; ?>/api/admin_disputes.php?action=close', { id: id });
            if(d.success){showToast('Closed','success');loadTickets();loadStats()}
            else showToast(d.message||'Failed','error');
        }
        
        function escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML}
    </script>
</body>
</html>