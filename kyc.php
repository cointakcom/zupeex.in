<?php
/**
 * ======================================================
 * KYC.PHP - Dedicated KYC Verification Page (CSRF FIXED)
 * Zupeex - Multi-step KYC Wizard
 * Version: 1.2.0 - CSRF AUTO-SYNC INTEGRATION
 * ======================================================
 */

if (!defined('BASE_PATH')) { define('BASE_PATH', __DIR__); }
require_once __DIR__ . '/config/db.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// 🔥 CSRF Token - CSRFToken class se
$csrf_token = CSRFToken::generate();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/' || $basePath == '') $basePath = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title>KYC Verification - Zupeex</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Slab:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Roboto Slab', serif; background: #FFFCF8; color: #000000; min-height: 100vh; }
        .kyc-header { background: #7D02AB; color: #FFFFFF; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 4px 15px rgba(125,2,171,0.15); }
        .kyc-header .back-btn { background: rgba(255,255,255,0.2); border: none; color: #FFFFFF; width: 36px; height: 36px; border-radius: 50%; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center; text-decoration: none; font-weight: 800; }
        .kyc-header h1 { font-size: 17px; font-weight: 800; }
        .kyc-header .shield { font-size: 20px; }

        .kyc-container { max-width: 520px; margin: 0 auto; padding: 20px 16px 60px; }

        .step-tracker { display: flex; justify-content: space-between; margin-bottom: 28px; position: relative; }
        .step-tracker::before { content: ''; position: absolute; top: 16px; left: 8%; right: 8%; height: 2px; background: #F5E6FF; z-index: 0; }
        .step-tracker .step-item { flex: 1; text-align: center; position: relative; z-index: 1; }
        .step-tracker .step-circle { width: 32px; height: 32px; border-radius: 50%; background: #FFFFFF; border: 2px solid rgba(125,2,171,0.2); color: #555555; display: flex; align-items: center; justify-content: center; margin: 0 auto 6px; font-weight: 800; font-size: 13px; transition: all 0.2s ease; }
        .step-tracker .step-item.done .step-circle { background: #047857; border-color: #047857; color: #FFFFFF; }
        .step-tracker .step-item.active .step-circle { background: #7D02AB; border-color: #7D02AB; color: #FFFFFF; box-shadow: 0 0 0 4px rgba(125,2,171,0.15); }
        .step-tracker .step-label { font-size: 10px; color: #555555; font-weight: 700; }
        .step-tracker .step-item.active .step-label { color: #7D02AB; }

        .kyc-card { background: #FFFFFF; border-radius: 16px; padding: 24px 20px; box-shadow: 0 4px 15px rgba(125,2,171,0.1); margin-bottom: 16px; }
        .kyc-card h2 { font-size: 18px; font-weight: 800; margin-bottom: 4px; color: #7D02AB; }
        .kyc-card .card-sub { font-size: 13px; color: #555555; margin-bottom: 20px; font-weight: 700; }

        .form-row { margin-bottom: 16px; }
        .form-row label { display: block; font-size: 12px; font-weight: 800; color: #7D02AB; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
        .form-row input, .form-row select { width: 100%; padding: 12px 14px; border: 1.5px solid rgba(125,2,171,0.2); border-radius: 10px; font-size: 14px; font-family: 'Roboto Slab', serif; color: #000000; background: #FFFFFF; }
        .form-row input:focus, .form-row select:focus { outline: none; border-color: #7D02AB; }
        .form-row .hint { font-size: 11px; color: #555555; margin-top: 4px; font-weight: 700; }
        .form-row .err { font-size: 12px; color: #B91C1C; margin-top: 4px; display: none; font-weight: 700; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        .upload-box { border: 2px dashed rgba(125,2,171,0.3); border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; background: #FFFFFF; position: relative; overflow: hidden; }
        .upload-box.has-file { border-color: #047857; border-style: solid; background: #F0FDF9; }
        .upload-box input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .upload-box .upload-icon { font-size: 28px; margin-bottom: 6px; }
        .upload-box .upload-text { font-size: 13px; font-weight: 800; color: #7D02AB; }
        .upload-box .upload-hint { font-size: 11px; color: #555555; margin-top: 4px; font-weight: 700; }
        .upload-preview { max-width: 100%; max-height: 140px; border-radius: 8px; margin-top: 10px; }

        .btn-primary { width: 100%; padding: 14px; background: #7D02AB; color: #FFFFFF; border: none; border-radius: 12px; font-weight: 800; font-size: 15px; cursor: pointer; font-family: 'Roboto Slab', serif; margin-top: 8px; }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-secondary { width: 100%; padding: 12px; background: transparent; color: #7D02AB; border: 1.5px solid rgba(125,2,171,0.3); border-radius: 12px; font-weight: 800; font-size: 14px; cursor: pointer; font-family: 'Roboto Slab', serif; margin-top: 8px; }

        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 800; }
        .status-badge.pending { background: #FEF3C7; color: #B45309; }
        .status-badge.verified { background: #D1FAE5; color: #047857; }
        .status-badge.rejected { background: #FEE2E2; color: #B91C1C; }
        .status-badge.not_submitted { background: #F3F4F6; color: #555555; }

        .review-row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px solid #F5E6FF; }
        .review-row:last-child { border-bottom: none; }
        .review-row .rr-label { font-size: 13px; color: #555555; font-weight: 700; }
        .review-row .rr-value { font-size: 13px; font-weight: 800; color: #000000; }
        .rejection-note { background: #FEF2F2; border: 1px solid #FECACA; border-radius: 8px; padding: 10px 12px; font-size: 12px; color: #B91C1C; margin-top: 8px; font-weight: 700; }

        .info-banner { background: #F5E6FF; border-radius: 10px; padding: 12px 14px; font-size: 12px; color: #7D02AB; margin-bottom: 16px; display: flex; gap: 8px; align-items: flex-start; font-weight: 700; }
        .toast-kyc { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(100px); background: #7D02AB; color: #FFFFFF; padding: 12px 24px; border-radius: 10px; font-size: 13px; font-weight: 800; opacity: 0; transition: all 0.3s ease; z-index: 999; max-width: 90%; text-align: center; }
        .toast-kyc.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast-kyc.error { background: #B91C1C; }
        .toast-kyc.success { background: #047857; }
    </style>
</head>
<body>
    <div class="kyc-header">
        <a href="index.php" class="back-btn">←</a>
        <span class="shield">🛡️</span>
        <h1>KYC Verification</h1>
    </div>

    <div class="kyc-container">
        <div class="step-tracker" id="stepTracker">
            <div class="step-item active" data-step="1"><div class="step-circle">1</div><div class="step-label">Personal</div></div>
            <div class="step-item" data-step="2"><div class="step-circle">2</div><div class="step-label">PAN</div></div>
            <div class="step-item" data-step="3"><div class="step-circle">3</div><div class="step-label">Aadhaar</div></div>
            <div class="step-item" data-step="4"><div class="step-circle">4</div><div class="step-label">Review</div></div>
        </div>

        <!-- STEP 1: Personal Details -->
        <div class="kyc-card step-panel" id="stepPanel1">
            <h2>Personal Details</h2>
            <p class="card-sub">Enter your details exactly as they appear on your government ID</p>
            <div class="form-row">
                <label>Full Name</label>
                <input type="text" id="fullName" placeholder="As per PAN / Aadhaar card">
                <div class="err" id="err-fullName">Enter a valid name (letters and spaces only)</div>
            </div>
            <div class="form-row">
                <label>Date of Birth</label>
                <input type="date" id="dob">
                <div class="err" id="err-dob">You must be at least 18 years old</div>
            </div>
            <div class="form-row">
                <label>Address</label>
                <input type="text" id="address" placeholder="House no, street, area">
                <div class="err" id="err-address">Address is required</div>
            </div>
            <div class="form-grid-2">
                <div class="form-row"><label>City</label><input type="text" id="city" placeholder="City"></div>
                <div class="form-row"><label>State</label><input type="text" id="state" placeholder="State"></div>
            </div>
            <div class="form-row">
                <label>PIN Code</label>
                <input type="text" id="pincode" placeholder="6-digit PIN code" maxlength="6" inputmode="numeric">
                <div class="err" id="err-pincode">PIN code must be exactly 6 digits</div>
            </div>
            <button class="btn-primary" id="btnStep1Next">Continue to PAN Verification →</button>
        </div>

        <!-- STEP 2: PAN -->
        <div class="kyc-card step-panel" id="stepPanel2" style="display:none;">
            <h2>PAN Card Verification</h2>
            <p class="card-sub">Upload a clear photo of your PAN card</p>
            <div id="panStatusBox"></div>
            <div class="form-row">
                <label>PAN Number</label>
                <input type="text" id="panNumber" placeholder="ABCDE1234F" maxlength="10" style="text-transform:uppercase;">
                <div class="err" id="err-panNumber">Format must be like ABCDE1234F</div>
            </div>
            <div class="form-row">
                <label>PAN Card Photo</label>
                <label class="upload-box" id="panUploadBox">
                    <input type="file" id="panImage" accept="image/jpeg,image/png,image/webp">
                    <div class="upload-icon">📄</div>
                    <div class="upload-text">Tap to upload PAN card photo</div>
                    <div class="upload-hint">JPG, PNG or WebP — max 5MB</div>
                    <img class="upload-preview" id="panPreview" style="display:none;">
                </label>
            </div>
            <button class="btn-primary" id="btnStep2Next">Submit PAN & Continue →</button>
            <button class="btn-secondary" onclick="goToStep(1)">← Back</button>
        </div>

        <!-- STEP 3: Aadhaar -->
        <div class="kyc-card step-panel" id="stepPanel3" style="display:none;">
            <h2>Aadhaar Card Verification</h2>
            <p class="card-sub">Upload clear photos of both sides of your Aadhaar card</p>
            <div id="aadhaarStatusBox"></div>
            <div class="form-row">
                <label>Aadhaar Number</label>
                <input type="text" id="aadhaarNumber" placeholder="12-digit Aadhaar number" maxlength="12" inputmode="numeric">
                <div class="err" id="err-aadhaarNumber">Aadhaar number must be exactly 12 digits</div>
            </div>
            <div class="form-row">
                <label>Front Side</label>
                <label class="upload-box" id="aadhaarFrontBox">
                    <input type="file" id="aadhaarFront" accept="image/jpeg,image/png,image/webp">
                    <div class="upload-icon">🪪</div>
                    <div class="upload-text">Tap to upload front side</div>
                    <div class="upload-hint">JPG, PNG or WebP — max 5MB</div>
                    <img class="upload-preview" id="aadhaarFrontPreview" style="display:none;">
                </label>
            </div>
            <div class="form-row">
                <label>Back Side</label>
                <label class="upload-box" id="aadhaarBackBox">
                    <input type="file" id="aadhaarBack" accept="image/jpeg,image/png,image/webp">
                    <div class="upload-icon">🪪</div>
                    <div class="upload-text">Tap to upload back side</div>
                    <div class="upload-hint">JPG, PNG or WebP — max 5MB</div>
                    <img class="upload-preview" id="aadhaarBackPreview" style="display:none;">
                </label>
            </div>
            <button class="btn-primary" id="btnStep3Next">Submit Aadhaar & Continue →</button>
            <button class="btn-secondary" onclick="goToStep(2)">← Back</button>
        </div>

        <!-- STEP 4: Review / Status -->
        <div class="kyc-card step-panel" id="stepPanel4" style="display:none;">
            <h2>Verification Status</h2>
            <p class="card-sub">An admin will review your documents shortly</p>
            <div class="info-banner">ℹ️ <span>Verification usually takes 24-48 hours. You'll be able to withdraw once both PAN and Aadhaar show as Verified.</span></div>
            <div id="reviewList"></div>
            <button class="btn-secondary" onclick="window.location.href='index.php'">Back to Home</button>
        </div>
    </div>

    <div class="toast-kyc" id="kycToast"></div>

    <!-- 🔥 AuthHelper for CSRF -->
    <script src="<?php echo htmlspecialchars($basePath); ?>/assets/js/auth-helper.js"></script>
    <script>
        const BASE_PATH = '<?php echo htmlspecialchars($basePath); ?>';
        const CSRF = '<?php echo htmlspecialchars($csrf_token); ?>';
        let currentStep = 1;
        let statusData = null;

        // 🔥 CSRF Token getter
        async function getCsrfToken() {
            if (window.AuthHelper && typeof window.AuthHelper.getCsrfToken === 'function') {
                return await window.AuthHelper.getCsrfToken();
            }
            return CSRF;
        }

        function showToast(msg, type) {
            const t = document.getElementById('kycToast');
            t.textContent = msg;
            t.className = 'toast-kyc show ' + (type || '');
            clearTimeout(t._to);
            t._to = setTimeout(() => t.classList.remove('show'), 3500);
        }

        function goToStep(step) {
            currentStep = step;
            document.querySelectorAll('.step-panel').forEach(p => p.style.display = 'none');
            document.getElementById('stepPanel' + step).style.display = 'block';
            document.querySelectorAll('.step-tracker .step-item').forEach(el => {
                const s = parseInt(el.getAttribute('data-step'));
                el.classList.toggle('active', s === step);
                el.classList.toggle('done', s < step);
            });
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function setupImagePreview(inputId, previewId, boxId) {
            document.getElementById(inputId).addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                if (file.size > 5 * 1024 * 1024) { showToast('File too large — max 5MB', 'error'); this.value = ''; return; }
                const reader = new FileReader();
                reader.onload = function(ev) {
                    const preview = document.getElementById(previewId);
                    preview.src = ev.target.result;
                    preview.style.display = 'block';
                    document.getElementById(boxId).classList.add('has-file');
                };
                reader.readAsDataURL(file);
            });
        }
        setupImagePreview('panImage', 'panPreview', 'panUploadBox');
        setupImagePreview('aadhaarFront', 'aadhaarFrontPreview', 'aadhaarFrontBox');
        setupImagePreview('aadhaarBack', 'aadhaarBackPreview', 'aadhaarBackBox');

        function clearErrors() { document.querySelectorAll('.err').forEach(e => e.style.display = 'none'); }
        function showError(id) { const el = document.getElementById('err-' + id); if (el) el.style.display = 'block'; }

        document.getElementById('btnStep1Next').addEventListener('click', async function() {
            clearErrors();
            const fullName = document.getElementById('fullName').value.trim();
            const dob = document.getElementById('dob').value;
            const address = document.getElementById('address').value.trim();
            const city = document.getElementById('city').value.trim();
            const state = document.getElementById('state').value.trim();
            const pincode = document.getElementById('pincode').value.trim();

            let valid = true;
            if (!/^[A-Za-z .]{3,100}$/.test(fullName)) { showError('fullName'); valid = false; }
            if (!dob) { showError('dob'); valid = false; }
            else {
                const age = (Date.now() - new Date(dob).getTime()) / (1000 * 60 * 60 * 24 * 365.25);
                if (age < 18) { showError('dob'); valid = false; }
            }
            if (address.length < 5) { showError('address'); valid = false; }
            if (!/^\d{6}$/.test(pincode)) { showError('pincode'); valid = false; }
            if (!valid) return;

            const btn = this;
            btn.disabled = true; btn.textContent = 'Saving...';
            try {
                // 🔥 Fresh CSRF token
                const csrf = await getCsrfToken();
                
                const res = await fetch(BASE_PATH + '/api/kyc.php?action=save_personal_details', {
                    method: 'POST', credentials: 'include',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ full_name: fullName, dob, address, city, state, pincode, csrf_token: csrf })
                });
                const data = await res.json();
                
                if (data.data && data.data.csrf_token && window.AuthHelper) {
                    window.AuthHelper.updateCsrfToken(data.data.csrf_token);
                }
                
                if (data.success) { showToast('Personal details saved', 'success'); goToStep(2); }
                else showToast(data.message || 'Failed to save', 'error');
            } catch (e) { showToast('Network error', 'error'); }
            finally { btn.disabled = false; btn.textContent = 'Continue to PAN Verification →'; }
        });

        document.getElementById('btnStep2Next').addEventListener('click', async function() {
            clearErrors();
            const panNumber = document.getElementById('panNumber').value.trim().toUpperCase();
            const panFile = document.getElementById('panImage').files[0];
            if (!/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(panNumber)) { showError('panNumber'); return; }
            if (!panFile) { showToast('Please upload your PAN card photo', 'error'); return; }

            const btn = this;
            btn.disabled = true; btn.textContent = 'Uploading...';
            try {
                // 🔥 Fresh CSRF token
                const csrf = await getCsrfToken();
                
                const fd = new FormData();
                fd.append('document_type', 'pan');
                fd.append('document_number', panNumber);
                fd.append('document_image_front', panFile);
                fd.append('csrf_token', csrf);
                
                const res = await fetch(BASE_PATH + '/api/kyc.php?action=submit', {
                    method: 'POST', credentials: 'include', 
                    headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf }, 
                    body: fd
                });
                const data = await res.json();
                
                if (data.data && data.data.csrf_token && window.AuthHelper) {
                    window.AuthHelper.updateCsrfToken(data.data.csrf_token);
                }
                
                if (data.success) { showToast('PAN submitted for verification', 'success'); goToStep(3); }
                else showToast(data.message || 'Failed to submit PAN', 'error');
            } catch (e) { showToast('Network error', 'error'); }
            finally { btn.disabled = false; btn.textContent = 'Submit PAN & Continue →'; }
        });

        document.getElementById('btnStep3Next').addEventListener('click', async function() {
            clearErrors();
            const aadhaarNumber = document.getElementById('aadhaarNumber').value.trim();
            const frontFile = document.getElementById('aadhaarFront').files[0];
            const backFile = document.getElementById('aadhaarBack').files[0];
            if (!/^\d{12}$/.test(aadhaarNumber)) { showError('aadhaarNumber'); return; }
            if (!frontFile) { showToast('Please upload the front side of your Aadhaar', 'error'); return; }

            const btn = this;
            btn.disabled = true; btn.textContent = 'Uploading...';
            try {
                // 🔥 Fresh CSRF token
                const csrf = await getCsrfToken();
                
                const fd = new FormData();
                fd.append('document_type', 'aadhaar');
                fd.append('document_number', aadhaarNumber);
                fd.append('document_image_front', frontFile);
                if (backFile) fd.append('document_image_back', backFile);
                fd.append('csrf_token', csrf);
                
                const res = await fetch(BASE_PATH + '/api/kyc.php?action=submit', {
                    method: 'POST', credentials: 'include', 
                    headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf }, 
                    body: fd
                });
                const data = await res.json();
                
                if (data.data && data.data.csrf_token && window.AuthHelper) {
                    window.AuthHelper.updateCsrfToken(data.data.csrf_token);
                }
                
                if (data.success) { showToast('Aadhaar submitted for verification', 'success'); goToStep(4); loadStatus(); }
                else showToast(data.message || 'Failed to submit Aadhaar', 'error');
            } catch (e) { showToast('Network error', 'error'); }
            finally { btn.disabled = false; btn.textContent = 'Submit Aadhaar & Continue →'; }
        });

        function badgeHtml(status) {
            const labels = { pending: '⏳ Pending', verified: '✅ Verified', rejected: '❌ Rejected', not_submitted: '— Not Submitted' };
            return '<span class="status-badge ' + status + '">' + (labels[status] || status) + '</span>';
        }

        async function loadStatus() {
            try {
                const res = await fetch(BASE_PATH + '/api/kyc.php?action=get_status', { 
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                if (!data.success) return;
                statusData = data.data;

                const pd = data.data.personal_details || {};
                if (pd.completed) {
                    document.getElementById('fullName').value = pd.full_name || '';
                    document.getElementById('dob').value = pd.dob ? pd.dob.split(' ')[0] : '';
                    document.getElementById('address').value = pd.address || '';
                    document.getElementById('city').value = pd.city || '';
                    document.getElementById('state').value = pd.state || '';
                    document.getElementById('pincode').value = pd.pincode || '';
                }

                const docs = data.data.documents || [];
                const pan = docs.find(d => d.document_type === 'pan');
                const aadhaar = docs.find(d => d.document_type === 'aadhaar');

                if (pan) {
                    document.getElementById('panStatusBox').innerHTML = '<div class="review-row"><span class="rr-label">Current status</span>' + badgeHtml(pan.status) + '</div>' + (pan.status === 'rejected' && pan.rejection_reason ? '<div class="rejection-note">Reason: ' + escapeHtml(pan.rejection_reason) + '</div>' : '');
                    if (pan.document_number_masked) document.getElementById('panNumber').placeholder = pan.document_number_masked;
                }
                if (aadhaar) {
                    document.getElementById('aadhaarStatusBox').innerHTML = '<div class="review-row"><span class="rr-label">Current status</span>' + badgeHtml(aadhaar.status) + '</div>' + (aadhaar.status === 'rejected' && aadhaar.rejection_reason ? '<div class="rejection-note">Reason: ' + escapeHtml(aadhaar.rejection_reason) + '</div>' : '');
                    if (aadhaar.document_number_masked) document.getElementById('aadhaarNumber').placeholder = aadhaar.document_number_masked;
                }

                let reviewHtml = '<div class="review-row"><span class="rr-label">Overall KYC Status</span>' + badgeHtml(data.data.kyc_status) + '</div>';
                reviewHtml += '<div class="review-row"><span class="rr-label">PAN Card</span>' + badgeHtml(pan ? pan.status : 'not_submitted') + '</div>';
                reviewHtml += '<div class="review-row"><span class="rr-label">Aadhaar Card</span>' + badgeHtml(aadhaar ? aadhaar.status : 'not_submitted') + '</div>';
                document.getElementById('reviewList').innerHTML = reviewHtml;

                if (data.data.kyc_status === 'verified' || (pan && aadhaar)) { goToStep(4); }
                else if (pd.completed && pan) { goToStep(3); }
                else if (pd.completed) { goToStep(2); }
            } catch (e) {}
        }

        function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

        loadStatus();
    </script>
</body>
</html>