<?php
/**
 * ======================================================
 * ADMIN SETTINGS.PHP - System Settings (CSRF FIXED)
 * Ludo Tournament Platform - Admin Settings Dashboard
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
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/admin') $basePath = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Roboto Slab',serif;background:#FFFCF8;color:#000000;min-height:100vh}
        .admin-container{max-width:1200px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#7D02AB;border-radius:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:800;color:#FFFFFF}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions span{color:#FFFFFF;font-weight:700}
        .admin-header-actions a{color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.3);border-radius:8px}
        .admin-header-actions a:hover{background:rgba(255,255,255,0.15)}
        .admin-header-actions a.logout{color:#FF6B6B;border-color:rgba(255,100,100,0.4)}
        .settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
        .settings-section{background:#FFFFFF;border-radius:14px;padding:24px;border:1px solid rgba(125,2,171,0.1);box-shadow:0 4px 15px rgba(125,2,171,0.15)}
        .settings-section h2{font-size:18px;font-weight:800;margin-bottom:20px;color:#7D02AB}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#000000;margin-bottom:4px}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(125,2,171,0.2);border-radius:8px;background:#FFFFFF;color:#000000;font-size:14px;font-family:'Roboto Slab',serif}
        .form-group input:focus,.form-group textarea:focus{outline:none;border-color:#7D02AB}
        .form-group textarea{resize:vertical;min-height:80px}
        .checkbox-label{display:flex;align-items:center;gap:8px;cursor:pointer;color:#000000;font-weight:700}
        .checkbox-label input[type="checkbox"]{width:18px;height:18px;accent-color:#7D02AB}
        .btn-save{padding:12px 32px;border:none;border-radius:10px;background:#7D02AB;color:#FFFFFF;font-weight:800;font-size:16px;cursor:pointer;font-family:'Roboto Slab',serif;margin-top:8px}
        .btn-save:hover{transform:scale(1.02);box-shadow:0 4px 15px rgba(125,2,171,0.3)}
        .btn-save:disabled{opacity:0.6}
        .maintenance-box{background:rgba(239,68,68,0.05);border:1px solid rgba(239,68,68,0.1);border-radius:12px;padding:20px;margin-top:16px}
        .maintenance-box.active{border-color:rgba(239,68,68,0.3)}
        .status-indicator{width:12px;height:12px;border-radius:50%;display:inline-block}
        .status-indicator.on{background:#DC2626;box-shadow:0 0 20px rgba(220,38,38,0.3)}
        .status-indicator.off{background:#059669;box-shadow:0 0 20px rgba(5,150,105,0.3)}
        .btn-toggle{padding:10px 24px;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;font-family:'Roboto Slab',serif}
        .btn-toggle.active{background:rgba(239,68,68,0.2);color:#B91C1C}
        .btn-toggle.inactive{background:rgba(16,185,129,0.2);color:#047857}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:800;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{background:rgba(16,185,129,0.2);color:#047857}
        .toast.error{background:rgba(239,68,68,0.2);color:#B91C1C}
        @media(max-width:768px){.settings-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>⚙️ System Settings</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a><a href="admin_users.php">👥 Users</a><a href="kyc.php">🛡️ KYC</a><a href="withdrawals.php">🏦 Withdrawals</a><a href="deposits.php">💰 Deposits</a><a href="disputes.php">📋 Disputes</a><a href="tournaments.php">🏆 Tournaments</a><a href="tickets.php">🎟️ Tickets</a><a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        <div class="settings-grid" id="settingsContainer"><div style="text-align:center;padding:40px;color:#000000">Loading...</div></div>
    </div>
    <div class="toast" id="adminToast"></div>
    <script>
        const BASE_PATH = '<?php echo $basePath; ?>';
        
        const SettingsApp = {
            settings: {}, csrfToken: '<?php echo $csrf_token; ?>',
            
            init(){this.loadSettings()},
            
            // 🔥 CSRF Token Auto-Refresh
            async refreshCsrfToken() {
                try {
                    const resp = await fetch(BASE_PATH + '/api/auth.php?action=get_csrf', {
                        method: 'GET',
                        credentials: 'include',
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await resp.json();
                    if (data.success && data.data && data.data.csrf_token) {
                        this.csrfToken = data.data.csrf_token;
                        return data.data.csrf_token;
                    }
                    return null;
                } catch (e) {
                    console.warn('[CSRF] Refresh error:', e);
                    return null;
                }
            },
            
            loadSettings(){
                fetch(BASE_PATH + '/api/admin_settings.php?action=get_settings', { credentials: 'include' }).then(r=>r.json()).then(d=>{if(d.success){this.settings=d.data.settings;this.render()}}).catch(()=>this.showToast('Error loading settings','error'))
            },
            
            render(){
                const groups={financial:'💰 Financial',gameplay:'🎮 Gameplay',system:'🔧 System',kyc:'🛡️ KYC',withdrawal:'🏦 Withdrawal',referral:'🎁 Referral'};
                let html='';
                for(const[gk,gl]of Object.entries(groups)){
                    if(this.settings[gk]&&this.settings[gk].length>0){
                        html+=`<div class="settings-section" data-group="${gk}"><h2>${gl}</h2>`;
                        this.settings[gk].forEach(s=>{if(s.is_editable){html+=this.renderField(s)}});
                        if(gk==='system')html+=this.renderMaintenance();
                        html+=`<button class="btn-save" onclick="SettingsApp.saveGroup('${gk}', event)">💾 Save ${gl}</button></div>`;
                    }
                }
                document.getElementById('settingsContainer').innerHTML=html;
            },
            
            renderField(s){
                const id='setting_'+s.key;let input='';
                switch(s.type){
                    case'boolean':input=`<div class="checkbox-label"><input type="checkbox" id="${id}" ${s.value?'checked':''}><label for="${id}">Enabled</label></div>`;break;
                    case'integer':case'decimal':input=`<input type="number" id="${id}" value="${s.value}" step="${s.type==='decimal'?'0.01':'1'}">`;break;
                    case'text':input=`<textarea id="${id}" rows="3">${this.escapeHtml(String(s.value))}</textarea>`;break;
                    default:input=`<input type="text" id="${id}" value="${this.escapeHtml(String(s.value))}">`;
                }
                return `<div class="form-group"><label>${this.formatLabel(s.key)}</label>${input}</div>`;
            },
            
            renderMaintenance(){
                const mm=this.getSettingValue('maintenance_mode')||false;
                const msg=this.getSettingValue('maintenance_message')||'';
                return `<div class="maintenance-box ${mm?'active':''}"><div style="margin-bottom:12px"><span class="status-indicator ${mm?'on':'off'}"></span> <strong>Maintenance: ${mm?'🔴 ENABLED':'🟢 DISABLED'}</strong></div><div class="form-group"><label>Message</label><input type="text" id="maintenance_message_input" value="${this.escapeHtml(msg)}"></div><button class="btn-toggle ${mm?'active':'inactive'}" onclick="SettingsApp.toggleMaintenance()">${mm?'🔴 Disable':'🟢 Enable'}</button></div>`;
            },
            
            getSettingValue(key){for(const g of Object.values(this.settings)){for(const s of g){if(s.key===key)return s.value}}return null},
            
            saveGroup(gk, evt){
                const el=document.querySelector(`[data-group="${gk}"]`);if(!el)return;
                const settings={};
                el.querySelectorAll('.form-group input,.form-group textarea').forEach(inp=>{
                    if(inp.id&&inp.id.startsWith('setting_')){
                        const key=inp.id.replace('setting_','');
                        let val=inp.value;
                        if(inp.type==='checkbox')val=inp.checked;
                        else if(inp.type==='number')val=parseFloat(val);
                        settings[key]=val;
                    }
                });
                if(gk==='system'){const mi=document.getElementById('maintenance_message_input');if(mi)settings['maintenance_message']=mi.value}
                const btn=(evt && evt.target) ? evt.target : el.querySelector('.btn-save');
                this.updateSettings(settings, btn);
            },
            
            async updateSettings(settings, btn){
                const originalText = btn ? btn.textContent : '';
                if(btn){btn.disabled=true;btn.textContent='Saving...';}
                
                try {
                    await this.refreshCsrfToken();
                    const response = await fetch(BASE_PATH + '/api/admin_settings.php?action=update_settings', {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': this.csrfToken
                        },
                        body: JSON.stringify({ settings: settings, csrf_token: this.csrfToken })
                    });
                    const d = await response.json();
                    
                    if (!d.success && d.data && d.data.refresh_needed) {
                        if (d.data.csrf_token) this.csrfToken = d.data.csrf_token;
                        const retryResponse = await fetch(BASE_PATH + '/api/admin_settings.php?action=update_settings', {
                            method: 'POST',
                            credentials: 'include',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': this.csrfToken
                            },
                            body: JSON.stringify({ settings: settings, csrf_token: this.csrfToken })
                        });
                        const retryData = await retryResponse.json();
                        if (retryData.success) { this.showToast('Saved!', 'success'); this.loadSettings(); }
                        else this.showToast(retryData.message || 'Failed', 'error');
                    } else {
                        if (d.success) { this.showToast('Saved!', 'success'); this.loadSettings(); }
                        else this.showToast(d.message || 'Failed', 'error');
                    }
                } catch (e) {
                    this.showToast('Error', 'error');
                } finally {
                    if(btn){btn.disabled=false;btn.textContent=originalText||'💾 Save';}
                }
            },
            
            async toggleMaintenance(){
                const current=this.getSettingValue('maintenance_mode')||false;
                const msg=document.getElementById('maintenance_message_input')?.value||'Maintenance in progress';
                if(!confirm(`${current?'Disable':'Enable'} maintenance?`))return;
                
                try {
                    await this.refreshCsrfToken();
                    const response = await fetch(BASE_PATH + '/api/admin_settings.php?action=toggle_maintenance', {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': this.csrfToken
                        },
                        body: JSON.stringify({ enable: !current, message: msg, csrf_token: this.csrfToken })
                    });
                    const d = await response.json();
                    
                    if (!d.success && d.data && d.data.refresh_needed) {
                        if (d.data.csrf_token) this.csrfToken = d.data.csrf_token;
                        const retryResponse = await fetch(BASE_PATH + '/api/admin_settings.php?action=toggle_maintenance', {
                            method: 'POST',
                            credentials: 'include',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': this.csrfToken
                            },
                            body: JSON.stringify({ enable: !current, message: msg, csrf_token: this.csrfToken })
                        });
                        const retryData = await retryResponse.json();
                        if (retryData.success) { this.showToast(retryData.message, 'success'); this.loadSettings(); }
                        else this.showToast(retryData.message || 'Failed', 'error');
                    } else {
                        if (d.success) { this.showToast(d.message, 'success'); this.loadSettings(); }
                        else this.showToast(d.message || 'Failed', 'error');
                    }
                } catch (e) {
                    this.showToast('Error', 'error');
                }
            },
            
            formatLabel(key){return key.replace(/_/g,' ').replace(/\b\w/g,l=>l.toUpperCase())},
            escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML},
            showToast(m,t='info'){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';clearTimeout(toast._timeout);toast._timeout=setTimeout(()=>toast.classList.remove('show'),4000)}
        };
        document.addEventListener('DOMContentLoaded',()=>SettingsApp.init());
    </script>
</body>
</html>