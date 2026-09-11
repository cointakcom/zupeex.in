<?php
/**
 * ======================================================
 * INDEX.PHP - COMPLETE SPA (PREMIUM CASINO MODALS)
 * Zupeex - All Features Connected
 * Version: 16.1.0 - CASINO UI UPGRADED
 * ======================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';

// 🔥 CSRF Token Generation
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}
$csrf_token = $_SESSION['csrf_token'];

// NOTE: The dashboard is rendered inside this same SPA (see #page-dashboard below),
// so a logged-in user simply continues to load this page — no server redirect needed.

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath == '/' || $basePath == '') $basePath = '';

function navIconUrl($basePath, $filename) {
    $fsPath = __DIR__ . '/assets/images/' . $filename;
    $v = file_exists($fsPath) ? filemtime($fsPath) : '0';
    return htmlspecialchars($basePath) . '/assets/images/' . $filename . '?v=' . $v;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#7D02AB">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title>Zupeex - Play & Win Real Cash</title>
    <link rel="icon" type="image/jpeg" href="<?php echo htmlspecialchars($basePath); ?>/assets/images/logo.jpg">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($basePath); ?>/assets/images/logo.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Slab:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>/assets/css/zupee-style.css">
    <link rel="manifest" href="<?php echo htmlspecialchars($basePath); ?>/manifest.json">
    
    <style>
        :root {
            --zupeex-bg: #FFFCF8;
            --zupeex-purple: #7D02AB;
            --zupeex-purple-dark: #6A0292;
            --zupeex-purple-light: #9B30C4;
            --zupeex-text-on-purple: #FFFFFF;
            --zupeex-text-dark: #000000;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; }
        body { font-family: 'Roboto Slab', serif; background: var(--zupeex-bg); color: var(--zupeex-text-dark); }
        
        #app-wrapper {
            width: 100%; max-width: 480px; height: 100vh; height: 100dvh;
            margin: 0 auto; position: relative; overflow: hidden;
            display: flex; flex-direction: column; background: var(--zupeex-bg);
        }
        @media (min-width: 481px) {
            #app-wrapper {
                margin: 16px auto; border-radius: 28px;
                height: calc(100vh - 32px); height: calc(100dvh - 32px);
                box-shadow: 0 4px 15px rgba(125,2,171,0.15);
            }
        }
        
        .zupee-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 16px; background: #7D02AB;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0; z-index: 100; min-height: 56px; position: relative;
            box-shadow: 0 4px 15px rgba(125,2,171,0.15);
        }
        .header-left { display: flex; align-items: center; gap: 10px; }
        .logo-icon { width: 40px; height: 40px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .logo-text { display: flex; flex-direction: column; }
        .logo-title { font-size: 18px; font-weight: 800; color: #FFFFFF; }
        .logo-subtitle { font-size: 10px; color: rgba(255,255,255,0.8); text-transform: uppercase; font-weight: 700; }
        .header-right { display: flex; align-items: center; gap: 8px; }
        .btn-wallet-badge { display: flex; align-items: center; gap: 6px; padding: 8px 14px; background: rgba(255,255,255,0.2); color: white; border: none; border-radius: 9999px; font-weight: 800; font-size: 13px; cursor: pointer; font-family: inherit; transition: background 0.3s ease; }
        .btn-wallet-badge:hover { background: rgba(255,255,255,0.3); transform: scale(1.03); }
        .btn-login-sm { padding: 8px 16px; background: #FFFFFF; color: #7D02AB; border: none; border-radius: 9999px; font-weight: 800; font-size: 13px; cursor: pointer; font-family: inherit; }
        .btn-login-sm:hover { transform: scale(1.03); }
        
        .main-content { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 0 16px 0 16px; -webkit-overflow-scrolling: touch; background: var(--zupeex-bg); }
        
        .page { display: none; background: var(--zupeex-bg); min-height: 100%; }
        .page.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        .bottom-nav-zupee {
            display: flex; justify-content: space-around; align-items: center;
            padding: 8px 4px 12px; background: #7D02AB;
            border-top: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0; z-index: 100; min-height: 64px;
            box-shadow: 0 -2px 12px rgba(125,2,171,0.15);
        }
        .bn-item { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 6px 12px; border: none; background: none; color: rgba(255,255,255,0.8); font-size: 10px; font-weight: 800; cursor: pointer; font-family: inherit; }
        .bn-item .bn-icon { font-size: 22px; }
        .bn-item .bn-icon img { width: 44px; height: 44px; object-fit: contain; display: inline-block; vertical-align: middle; }
        .bn-item .bn-icon svg { width: 22px; height: 22px; }
        .bn-center-btn img { width: 55px; height: 55px; object-fit: contain; }
        .bn-item.active { color: #FFFFFF; font-weight: 800; }
        .bn-center { position: relative; margin-top: -20px; }
        .bn-center-btn { width: 52px; height: 52px; border-radius: 50%; background: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); }
        
        .back-btn-header { display: flex; align-items: center; gap: 10px; padding: 14px 16px; background: #7D02AB; color: white; border: none; font-size: 15px; font-weight: 800; cursor: pointer; font-family: inherit; width: 100%; border-bottom: 1px solid rgba(255,255,255,0.1); transition: background 0.2s; }
        .back-btn-header:hover { background: #6A0292; }

        .banner-carousel { position: relative; overflow: hidden; margin: 12px 0; border-radius: 16px; }
        .banner-track { display: flex; transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1); }
        .banner-slide { min-width: 100%; flex-shrink: 0; }
        .banner-card { min-height: 180px; cursor: pointer; }
        .banner-1 { background-color: #7D02AB; background-size: cover; background-position: center; }
        .banner-2 { background-color: #9B30C4; background-size: cover; background-position: center; }
        .banner-3 { background-color: #6A0292; background-size: cover; background-position: center; }
        .banner-4 { background-color: #7D02AB; background-size: cover; background-position: center; }
        .banner-dots { display: flex; justify-content: center; gap: 8px; padding: 8px 0; position: absolute; bottom: 8px; left: 0; right: 0; }
        .banner-dots .dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.4); cursor: pointer; transition: all 0.3s ease; }
        .banner-dots .dot.active { background: white; width: 24px; border-radius: 4px; }
        
        .section-container { margin-bottom: 20px; }
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .section-title { font-size: 18px; font-weight: 800; color: #000000; }
        
        .tournament-grid-zupee { display: flex; flex-direction: column; gap: 12px; }
        .ticket-mode-toggle { display: flex; gap: 8px; margin-bottom: 12px; }
        .tmt-btn { flex: 1; padding: 10px; border: 2px solid rgba(125,2,171,0.2); border-radius: 9999px; background: #FFFFFF; color: #7D02AB; font-weight: 800; font-size: 13px; cursor: pointer; font-family: inherit; }
        .tmt-btn.active { background: #7D02AB; color: #FFFFFF; border-color: #7D02AB; }
        .empty-msg { color: #555555; text-align: center; padding: 20px; font-size: 13px; font-weight: 700; }
        .tcz-waiting-tag { font-size: 11px; color: #047857; margin-top: 4px; font-weight: 700; }
        .ticket-spinner { width: 44px; height: 44px; border: 4px solid rgba(125,2,171,0.1); border-top-color: #7D02AB; border-radius: 50%; margin: 0 auto; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .tournament-card-zupee { background: #7D02AB; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(125,2,171,0.15); cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent; }
        .tournament-card-zupee:hover { transform: translateY(-2px); border-color: #9B30C4; }
        .tournament-card-zupee.featured-card { border-color: #FFFFFF; }
        .tournament-card-zupee.premium-card { border-color: rgba(255,255,255,0.3); }
        .tcz-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; background: rgba(255,255,255,0.1); }
        .tcz-badge { padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; color: #FFFFFF; }
        .badge-green { background: #047857; }
        .badge-orange { background: #B45309; }
        .badge-purple { background: #6A0292; }
        .badge-gold { background: #B45309; }
        .tcz-body { padding: 14px 16px; }
        .tcz-prize-row { display: flex; justify-content: space-between; align-items: center; }
        .tcz-prize-amount { font-size: 22px; font-weight: 800; color: #FFFFFF; }
        
        .how-to-play { display: flex; gap: 12px; }
        .htp-step { flex: 1; text-align: center; padding: 16px 8px; background: #7D02AB; border-radius: 12px; box-shadow: 0 4px 15px rgba(125,2,171,0.15); }
        .htp-number { width: 36px; height: 36px; border-radius: 50%; background: #FFFFFF; color: #7D02AB; display: flex; align-items: center; justify-content: center; font-weight: 800; margin: 0 auto 8px; }
        .htp-text strong { font-size: 14px; color: #FFFFFF; display: block; font-weight: 800; }
        .htp-text p { font-size: 11px; color: rgba(255,255,255,0.8); margin-top: 2px; font-weight: 700; }
        
        .wallet-balance-card { background: #7D02AB; border-radius: 16px; padding: 24px; text-align: center; box-shadow: 0 4px 15px rgba(125,2,171,0.15); }
        .wbc-label { font-size: 12px; color: rgba(255,255,255,0.8); text-transform: uppercase; font-weight: 700; }
        .wbc-amount { font-size: 42px; font-weight: 900; color: #FFFFFF; display: block; margin: 8px 0; }
        .wbc-actions { display: flex; gap: 12px; margin-top: 16px; }
        .btn-add-cash, .btn-withdraw { flex: 1; padding: 12px; border: none; border-radius: 12px; font-weight: 800; font-size: 14px; cursor: pointer; font-family: inherit; }
        .btn-add-cash { background: #FFFFFF; color: #7D02AB; }
        .btn-withdraw { background: rgba(255,255,255,0.2); color: #FFFFFF; border: 1px solid rgba(255,255,255,0.3); }
        
        .refer-hero-card { background: #7D02AB; border-radius: 16px; padding: 32px; text-align: center; box-shadow: 0 4px 15px rgba(125,2,171,0.15); }
        .refer-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 16px 0; }
        .refer-stat-card { background: #7D02AB; border-radius: 12px; padding: 14px 8px; text-align: center; box-shadow: 0 4px 15px rgba(125,2,171,0.15); }
        .refer-stat-card .rs-value { font-size: 20px; font-weight: 800; color: #FFFFFF; }
        .refer-stat-card .rs-label { font-size: 10px; color: rgba(255,255,255,0.8); margin-top: 2px; font-weight: 700; }
        .refer-rules-card { background: #7D02AB; border-radius: 14px; padding: 18px; margin: 16px 0; box-shadow: 0 4px 15px rgba(125,2,171,0.15); }
        .rule-row { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; font-size: 13px; color: rgba(255,255,255,0.85); font-weight: 700; }
        .rule-row .rule-icon { flex-shrink: 0; }
        .referral-item { background: #7D02AB; border-radius: 12px; padding: 14px; margin-bottom: 10px; box-shadow: 0 4px 15px rgba(125,2,171,0.15); }
        .referral-item .ri-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .referral-item .ri-name { font-weight: 800; color: #FFFFFF; font-size: 14px; }
        .referral-item .ri-date { font-size: 11px; color: rgba(255,255,255,0.7); font-weight: 700; }
        .referral-item .ri-status { font-size: 11px; font-weight: 800; padding: 3px 10px; border-radius: 12px; }
        .referral-item .ri-status.complete { background: rgba(16,185,129,0.2); color: #D1FAE5; }
        .referral-item .ri-status.in_progress { background: rgba(245,158,11,0.2); color: #FEF3C7; }
        .referral-item .ri-status.not_started { background: rgba(239,68,68,0.15); color: #FEE2E2; }
        .ri-progress-track { height: 8px; background: rgba(255,255,255,0.2); border-radius: 9999px; overflow: hidden; margin-top: 6px; }
        .ri-progress-fill { height: 100%; background: #FFFFFF; border-radius: 9999px; transition: width 0.3s ease; }
        .ri-progress-text { font-size: 11px; color: rgba(255,255,255,0.8); margin-top: 4px; text-align: right; font-weight: 700; }
        .refer-code-box { display: flex; gap: 8px; background: rgba(255,255,255,0.1); border-radius: 12px; padding: 8px; border: 2px dashed rgba(255,255,255,0.3); }
        .refer-code-box span { flex: 1; font-size: 20px; font-weight: 800; color: #FFFFFF; letter-spacing: 2px; padding: 8px; }
        .btn-copy { padding: 10px 24px; background: #FFFFFF; color: #7D02AB; border: none; border-radius: 12px; font-weight: 800; cursor: pointer; font-family: inherit; }
        
        .profile-header-card { background: #7D02AB; border-radius: 16px; padding: 24px; text-align: center; box-shadow: 0 4px 15px rgba(125,2,171,0.15); margin-bottom: 16px; }
        .profile-avatar-zupee { width: 80px; height: 80px; border-radius: 50%; background: #FFFFFF; color: #7D02AB; display: flex; align-items: center; justify-content: center; font-size: 36px; font-weight: 800; margin: 0 auto 12px; }
        .kyc-avatar-badge { position: absolute; bottom: 8px; right: -4px; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; border: 2px solid #7D02AB; }
        .kyc-avatar-badge.verified { background: #047857; }
        .kyc-avatar-badge.pending { background: #B45309; }
        .kyc-avatar-badge.rejected, .kyc-avatar-badge.not_submitted { background: #B91C1C; }
        .kyc-status-pill { display: inline-block; margin-top: 8px; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 800; }
        .kyc-status-pill.verified { background: rgba(16,185,129,0.2); color: #D1FAE5; }
        .kyc-status-pill.pending { background: rgba(245,158,11,0.2); color: #FEF3C7; }
        .kyc-status-pill.rejected, .kyc-status-pill.not_submitted { background: rgba(239,68,68,0.2); color: #FEE2E2; }
        .kyc-doc-status-row { display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.1); padding: 8px 12px; border-radius: 8px; margin-bottom: 6px; font-size: 13px; color: #FFFFFF; font-weight: 700; }
        .profile-menu-zupee { margin-top: 16px; }
        .pm-item { width: 100%; padding: 14px; background: #7D02AB; border: none; border-radius: 12px; font-size: 14px; font-weight: 800; color: #FFFFFF; text-align: left; cursor: pointer; font-family: inherit; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
        .pm-item:hover { background: #6A0292; }
        .pm-item svg { width: 18px; height: 18px; flex-shrink: 0; }
        
        .history-filters-zupee { display: flex; gap: 8px; margin-bottom: 16px; }
        .filter-btn-zupee { flex: 1; padding: 10px; border: 2px solid rgba(125,2,171,0.2); border-radius: 9999px; background: #FFFFFF; color: #7D02AB; font-weight: 800; font-size: 13px; cursor: pointer; font-family: inherit; }
        .filter-btn-zupee.active { background: #7D02AB; color: #FFFFFF; border-color: #7D02AB; }
        
        .share-btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 16px; }
        .share-btn { width: 48px; height: 48px; border-radius: 50%; border: none; font-size: 22px; cursor: pointer; transition: transform 0.2s; display: flex; align-items: center; justify-content: center; text-decoration: none; }
        .share-btn:hover { transform: scale(1.15); }
        .share-btn.whatsapp { background: #25D366; color: white; }
        .share-btn.telegram { background: #0088cc; color: white; }
        .share-btn.facebook { background: #1877F2; color: white; }
        .share-btn.twitter { background: #1DA1F2; color: white; }
        .share-btn.copy { background: #7D02AB; color: #FFFFFF; }
        
        .modal-overlay-zupee { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay-zupee.active { display: flex; }
        #authModal.modal-overlay-zupee {
            background: rgba(0,0,0,0.35) url('<?php echo $basePath; ?>/assets/images/auth-background_2.jpg') center top / cover no-repeat;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            padding-bottom: 1vh;
        }
        #authModal.modal-overlay-zupee.active { display: flex; }
        .modal-card-zupee { background: #7D02AB; border-radius: 20px; max-width: 400px; width: 100%; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(125,2,171,0.3); animation: modalSlideUp 0.3s ease; }
        #authModal .auth-modal-card {
            background: rgba(30, 15, 60, 0.35);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,0.15);
        }
        #authModal { padding: 0; }
        @media (max-width: 480px) {
            #authModal .auth-modal-card { align-self: flex-end; max-height: 70vh; border-radius: 24px 24px 0 0; margin-bottom: 0; width: 100%; max-width: 100%; }
        }
        @keyframes modalSlideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .modal-header-zupee { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px 12px; }
        .modal-header-zupee h2 { font-size: 22px; font-weight: 800; color: #FFFFFF; }
        .modal-close-zupee { width: 32px; height: 32px; border-radius: 50%; border: none; background: rgba(255,255,255,0.2); color: white; font-size: 16px; cursor: pointer; }
        .modal-body-zupee { padding: 12px 24px 24px; }
        .auth-form-zupee { display: none; }
        .auth-form-zupee.active { display: block; }
        .form-group-zupee { margin-bottom: 14px; }
        .quick-amount-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
        .quick-amount-btn { padding: 12px 6px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.15); background: rgba(255,255,255,0.1); color: #FFFFFF; font-weight: 800; font-size: 14px; cursor: pointer; transition: all 0.15s ease; font-family: inherit; }
        .quick-amount-btn:hover { background: rgba(255,255,255,0.2); }
        .quick-amount-btn.selected { background: #FFFFFF; border-color: #FFFFFF; color: #7D02AB; }
        .form-group-zupee label { display: block; font-size: 13px; font-weight: 800; color: #FFFFFF; margin-bottom: 4px; }
        .form-group-zupee input[type="text"], .form-group-zupee input[type="tel"], .form-group-zupee input[type="password"], .form-group-zupee input[type="email"], .form-group-zupee input[type="number"] { width: 100%; padding: 12px 14px; border: 2px solid rgba(255,255,255,0.2); border-radius: 12px; font-size: 14px; font-family: 'Roboto Slab', serif; background: rgba(255,255,255,0.1); color: #FFFFFF; outline: none; }
        .form-group-zupee input:focus { border-color: #FFFFFF; }
        .form-group-zupee input::placeholder { color: rgba(255,255,255,0.5); }
        .checkbox-group { display: flex; align-items: flex-start; gap: 10px; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; margin-top: 3px; accent-color: #7D02AB; }
        .checkbox-group label { font-size: 12px; color: rgba(255,255,255,0.8); font-weight: 700; }
        .btn-auth-submit { width: 100%; padding: 14px; background: #FFFFFF; color: #7D02AB; border: none; border-radius: 12px; font-weight: 800; font-size: 16px; cursor: pointer; font-family: 'Roboto Slab', serif; margin-top: 8px; }
        .btn-auth-submit:hover { transform: scale(1.02); }
        .auth-switch-text { text-align: center; font-size: 13px; color: rgba(255,255,255,0.8); margin-top: 14px; font-weight: 700; }
        .auth-switch-text a { color: #FFFFFF; text-decoration: none; font-weight: 800; }
        
        .popup-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 3000; justify-content: center; align-items: center; padding: 20px; }
        .popup-overlay.active { display: flex; }
        .popup-card { background: #FFFFFF; border-radius: 16px; padding: 0; width: 90%; max-width: 380px; position: relative; overflow: hidden; box-shadow: 0 20px 60px rgba(125,2,171,0.3); animation: popupIn 0.3s ease; }
        @keyframes popupIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .popup-card img { width: 100%; display: block; }
        .popup-close-btn { position: absolute; top: 10px; right: 10px; width: 32px; height: 32px; border-radius: 50%; background: rgba(125,2,171,0.8); color: white; border: 2px solid rgba(255,255,255,0.5); font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 10; transition: all 0.2s; }
        .popup-indicators { display: flex; justify-content: center; gap: 8px; padding: 12px; background: #FFFFFF; }
        .popup-dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(125,2,171,0.3); cursor: pointer; transition: all 0.3s; }
        .popup-dot.active { background: #7D02AB; width: 24px; border-radius: 4px; }
        
        .kyc-form input, .bank-form input, .kyc-form select { width: 100%; padding: 12px 14px; border: 2px solid rgba(255,255,255,0.2); border-radius: 8px; background: rgba(255,255,255,0.1); color: #FFFFFF; font-size: 14px; font-family: 'Roboto Slab', serif; margin-bottom: 10px; outline: none; transition: border-color 0.2s; }
        .kyc-form input:focus, .bank-form input:focus, .kyc-form select:focus { border-color: #FFFFFF; }
        .kyc-form select option { background: #7D02AB; color: #FFFFFF; }
        .lang-select-profile { width: 100%; padding: 12px 14px; border-radius: 8px; background: #FFFFFF; color: #000000; border: 2px solid rgba(125,2,171,0.2); font-family: 'Roboto Slab', serif; font-size: 14px; cursor: pointer; margin-top: 8px; outline: none; font-weight: 700; }
        .lang-select-profile:focus { border-color: #7D02AB; }
        .lang-select-profile option { background: #FFFFFF; color: #000000; }
        
        .toast-zupee { position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%) translateY(20px); padding: 12px 24px; border-radius: 30px; font-weight: 800; font-size: 14px; z-index: 4000; opacity: 0; transition: all 0.3s ease; pointer-events: none; white-space: nowrap; box-shadow: 0 4px 16px rgba(125,2,171,0.2); }
        .toast-zupee.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast-zupee.success { background: #D1FAE5; color: #047857; }
        .toast-zupee.error { background: #FEE2E2; color: #B91C1C; }
        .toast-zupee.info { background: #E0E7FF; color: #3730A3; }
        .toast-zupee.warning { background: #FEF3C7; color: #B45309; }
        
        @media (max-width: 480px) { .how-to-play { flex-direction: column; } }
        .header-logo-img { height: 36px; width: auto; max-width: 160px; object-fit: contain; }

        /* ==============================================
           🔥 PREMIUM CASINO MODALS - ADD MONEY & WITHDRAW           ============================================== */
        .casino-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(20, 0, 40, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); z-index: 3000; align-items: center; justify-content: center; padding: 16px; }
        .casino-modal-overlay.active { display: flex; animation: casinoFadeIn 0.3s ease; }
        @keyframes casinoFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .casino-modal-card { background: linear-gradient(145deg, #FFFFFF 0%, #F5E6FF 50%, #FFFFFF 100%); border-radius: 24px; max-width: 420px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 0 40px rgba(125,2,171,0.4), 0 0 80px rgba(125,2,171,0.2); border: 2px solid rgba(125,2,171,0.3); animation: casinoSlideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); position: relative; overflow: hidden; }
        @keyframes casinoSlideUp { from { opacity: 0; transform: translateY(50px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .casino-modal-card::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(45deg, transparent 40%, rgba(255,255,255,0.3) 50%, transparent 60%); animation: casinoShine 3s ease-in-out infinite; pointer-events: none; z-index: 0; }
        @keyframes casinoShine { 0%, 100% { transform: translateX(-100%) rotate(45deg); } 50% { transform: translateX(100%) rotate(45deg); } }
        .casino-modal-content { position: relative; z-index: 1; }
        .casino-modal-header { background: linear-gradient(135deg, #7D02AB 0%, #9B30C4 50%, #6A0292 100%); padding: 20px 24px; border-radius: 22px 22px 0 0; position: relative; }
        .casino-modal-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent, #FFFFFF, transparent); animation: casinoLineGlow 2s ease-in-out infinite; }
        @keyframes casinoLineGlow { 0%, 100% { opacity: 0.3; } 50% { opacity: 1; } }
        .casino-header-row { display: flex; align-items: center; justify-content: space-between; }
        .casino-header-title { display: flex; align-items: center; gap: 10px; }
        .casino-header-icon { width: 36px; height: 36px; border-radius: 10px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 20px; animation: casinoIconPulse 2s ease-in-out infinite; }
        @keyframes casinoIconPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .casino-header-text h2 { color: #FFFFFF; font-size: 18px; font-weight: 900; margin: 0; }
        .casino-header-text p { color: rgba(255,255,255,0.8); font-size: 11px; font-weight: 700; margin: 2px 0 0; }
        .casino-close-btn { width: 32px; height: 32px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.1); color: #FFFFFF; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease; }
        .casino-close-btn:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }
        .casino-modal-body { padding: 20px 24px 24px; }
        .casino-balance-display { text-align: center; margin-bottom: 20px; }
        .casino-balance-label { font-size: 11px; color: #7D02AB; text-transform: uppercase; letter-spacing: 2px; font-weight: 800; }
        .casino-balance-amount { font-size: 36px; font-weight: 900; color: #7D02AB; text-shadow: 0 0 20px rgba(125,2,171,0.3); animation: casinoAmountGlow 2s ease-in-out infinite; }
        @keyframes casinoAmountGlow { 0%, 100% { text-shadow: 0 0 10px rgba(125,2,171,0.3); } 50% { text-shadow: 0 0 30px rgba(125,2,171,0.6); } }
        .casino-quick-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
        .casino-amount-btn { padding: 14px 8px; border-radius: 14px; border: 2px solid rgba(125,2,171,0.2); background: #FFFFFF; color: #7D02AB; font-weight: 800; font-size: 14px; cursor: pointer; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); font-family: 'Roboto Slab', serif; position: relative; overflow: hidden; }
        .casino-amount-btn:hover { border-color: #7D02AB; transform: translateY(-3px); box-shadow: 0 8px 20px rgba(125,2,171,0.2); }
        .casino-amount-btn:active { transform: translateY(-1px) scale(0.95); }
        .casino-amount-btn.selected { background: linear-gradient(135deg, #7D02AB, #9B30C4); color: #FFFFFF; border-color: #7D02AB; box-shadow: 0 4px 15px rgba(125,2,171,0.4); animation: casinoSelectedPulse 1.5s ease-in-out infinite; }
        @keyframes casinoSelectedPulse { 0%, 100% { box-shadow: 0 4px 15px rgba(125,2,171,0.4); } 50% { box-shadow: 0 4px 25px rgba(125,2,171,0.7); } }
        .casino-input-label { font-size: 11px; color: #7D02AB; text-transform: uppercase; letter-spacing: 1px; font-weight: 800; margin-bottom: 6px; }
        .casino-input { width: 100%; padding: 14px 16px; border-radius: 14px; border: 2px solid rgba(125,2,171,0.2); background: #FFFFFF; color: #000000; font-size: 16px; font-weight: 700; font-family: 'Roboto Slab', serif; outline: none; transition: all 0.3s ease; margin-bottom: 16px; }
        .casino-input:focus { border-color: #7D02AB; box-shadow: 0 0 20px rgba(125,2,171,0.2); }
        .casino-cta-btn { width: 100%; padding: 16px; border: none; border-radius: 16px; background: linear-gradient(135deg, #7D02AB 0%, #9B30C4 50%, #6A0292 100%); color: #FFFFFF; font-weight: 900; font-size: 16px; cursor: pointer; font-family: 'Roboto Slab', serif; letter-spacing: 1px; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); position: relative; overflow: hidden; box-shadow: 0 4px 15px rgba(125,2,171,0.3); }
        .casino-cta-btn::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(45deg, transparent, rgba(255,255,255,0.2), transparent); animation: casinoBtnShine 2s ease-in-out infinite; }
        @keyframes casinoBtnShine { 0%, 100% { transform: translateX(-100%); } 50% { transform: translateX(100%); } }
        .casino-cta-btn:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(125,2,171,0.5); }
        .casino-cta-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .casino-security-badge { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 12px; font-size: 11px; color: #555555; font-weight: 700; }
        .casino-info-box { background: rgba(125,2,171,0.05); border: 1px solid rgba(125,2,171,0.15); border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; font-size: 12px; color: #555555; font-weight: 700; }
    </style>
</head>
<body>
    <div id="splashScreen">
        <img id="splashImage" src="<?php echo navIconUrl($basePath, 'loading.png'); ?>" alt="" onerror="this.style.display='none';">
        <div id="splashProgressTrack"><div id="splashProgressBar"></div></div>
        <div id="splashProgressPct">0%</div>
    </div>
    <style>
        #splashScreen { position: fixed; inset: 0; z-index: 99999; width: 100%; height: 100vh; height: 100dvh; background: #7D02AB; display: flex; align-items: center; justify-content: center; overflow: hidden; transition: opacity 0.4s ease; }
        #splashScreen.fade-out { opacity: 0; pointer-events: none; }
        #splashImage { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center; }
        #splashProgressTrack { position: absolute; left: 12%; right: 12%; bottom: 10%; height: 8px; border-radius: 9999px; background: rgba(255,255,255,0.25); overflow: hidden; z-index: 2; }
        #splashProgressBar { height: 100%; width: 1%; border-radius: 9999px; background: #FFFFFF; transition: width 0.1s linear; }
        #splashProgressPct { position: absolute; left: 0; right: 0; bottom: calc(10% + 16px); text-align: center; z-index: 2; color: #FFFFFF; font-family: 'Roboto Slab', serif; font-weight: 800; font-size: 14px; letter-spacing: 1px; text-shadow: 0 2px 8px rgba(0,0,0,0.4); }
    </style>
    <script>
        (function() {
            var DURATION_MS = 3000;
            var startTime = Date.now();
            var bar = document.getElementById('splashProgressBar');
            var pct = document.getElementById('splashProgressPct');
            var screen = document.getElementById('splashScreen');
            function tick() {
                var elapsed = Date.now() - startTime;
                var progress = Math.min(100, Math.max(1, Math.round((elapsed / DURATION_MS) * 100)));
                if (bar) bar.style.width = progress + '%';
                if (pct) pct.textContent = progress + '%';
                if (elapsed < DURATION_MS) { requestAnimationFrame(tick); }
                else { if (bar) bar.style.width = '100%'; if (pct) pct.textContent = '100%'; setTimeout(function() { if (screen) { screen.classList.add('fade-out'); setTimeout(function() { screen.remove(); }, 450); } }, 150); }
            }
            requestAnimationFrame(tick);
        })();
    </script>
    <div id="app-wrapper">
        <header class="zupee-header" id="mainHeader">
            <div class="header-left">
                <img src="<?php echo htmlspecialchars($basePath); ?>/assets/images/home-logo.png" alt="Zupeex" class="header-logo-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="logo-icon" style="display:none">🎲</div>
                <div class="logo-text"><span class="logo-title">Zupeex</span><span class="logo-subtitle">Skill Gaming</span></div>
            </div>
            <div class="header-right">
                <button class="btn-wallet-badge" id="headerWalletBtn"><span>💰</span><span id="headerBalance">₹0</span><span>+</span></button>
                <button class="btn-login-sm" id="headerLoginBtn" data-lang="login">Login</button>
            </div>
        </header>
        <main class="main-content" id="appMain">
            <section id="page-dashboard" class="page active">
                <div class="banner-carousel" id="bannerCarousel">
                    <div class="banner-track" id="bannerTrack">
                        <div class="banner-slide"><div class="banner-card banner-1" style="background-image:url('<?php echo htmlspecialchars($basePath); ?>/assets/images/banner-welcome.png')" onclick="window.app.openAuthModal('register')"></div></div>
                        <div class="banner-slide"><div class="banner-card banner-2" style="background-image:url('<?php echo htmlspecialchars($basePath); ?>/assets/images/banner-refer.png')" onclick="window.app.navigateTo('refer')"></div></div>
                        <div class="banner-slide"><div class="banner-card banner-3" style="background-image:url('<?php echo htmlspecialchars($basePath); ?>/assets/images/banner-tournament.png')" onclick="window.app.openAuthModal('login')"></div></div>
                        <div class="banner-slide"><div class="banner-card banner-4" style="background-image:url('<?php echo htmlspecialchars($basePath); ?>/assets/images/banner-safe.png')" onclick="window.app.openAuthModal('register')"></div></div>
                    </div>
                    <div class="banner-dots" id="bannerDots"><span class="dot active" data-index="0"></span><span class="dot" data-index="1"></span><span class="dot" data-index="2"></span><span class="dot" data-index="3"></span></div>
                </div>
                <div class="section-container">
                    <div class="section-header"><h3 class="section-title" data-lang="tournament">🎟️ Tournament Tickets</h3></div>
                    <div class="ticket-mode-toggle">
                        <button class="tmt-btn active" data-mode="1vs1" onclick="window.app.switchTicketMode('1vs1')">1 vs 1</button>
                        <button class="tmt-btn" data-mode="1vs4" onclick="window.app.switchTicketMode('1vs4')">1 vs 4</button>
                    </div>
                    <div class="tournament-grid-zupee" id="ticketGrid"><div class="empty-msg">Loading tickets...</div></div>
                </div>
                <div class="modal-overlay-zupee" id="ticketWaitingModal">
                    <div class="modal-card-zupee" style="max-width:340px;text-align:center;padding:32px 24px;">
                        <div class="ticket-spinner"></div>
                        <h3 style="color:#FFFFFF;margin:16px 0 8px;font-weight:800;">Finding opponent...</h3>
                        <p id="ticketWaitingCount" style="color:rgba(255,255,255,0.8);font-size:14px;margin-bottom:20px;font-weight:700;">Waiting for players (1/2)</p>
                        <button class="btn-auth-submit" onclick="window.app.cancelTicketQueue()">Cancel & Refund</button>
                    </div>
                </div>
                <div class="section-container">
                    <div class="section-header"><h3 class="section-title" data-lang="howToPlay">📖 How to Play</h3></div>
                    <div class="how-to-play">
                        <div class="htp-step"><div class="htp-number">1</div><div class="htp-text"><strong>Sign Up</strong><p>Create account in seconds</p></div></div>
                        <div class="htp-step"><div class="htp-number">2</div><div class="htp-text"><strong>Add Cash</strong><p>Deposit via UPI or cards</p></div></div>
                        <div class="htp-step"><div class="htp-number">3</div><div class="htp-text"><strong>Play & Win</strong><p>Beat opponents & withdraw</p></div></div>
                    </div>
                </div>
            </section>
            <section id="page-wallet" class="page">
                <div style="padding:16px;">
                    <div class="wallet-balance-card">
                        <span class="wbc-label" data-lang="balance">Available Balance</span>
                        <span class="wbc-amount" id="walletLarge">₹0.00</span>
                        <div class="wbc-actions">
                            <button class="btn-add-cash" id="addMoneyBtn" data-lang="addCash">+ Add Cash</button>
                            <button class="btn-withdraw" id="withdrawBtn" data-lang="withdraw">Withdraw</button>
                        </div>
                    </div>
                    <div id="walletTransactions" style="margin-top:16px;"></div>
                </div>
            </section>
            <section id="page-history" class="page">
                <div style="padding:16px;">
                    <h3 style="color:#000000;font-size:20px;font-weight:800;margin-bottom:16px;">📋 Complete History</h3>
                    <div class="history-filters-zupee">
                        <button class="filter-btn-zupee active" data-filter="all" onclick="window.app.filterHistory('all')">All</button>
                        <button class="filter-btn-zupee" data-filter="deposit" onclick="window.app.filterHistory('deposit')">Deposits</button>
                        <button class="filter-btn-zupee" data-filter="withdrawal" onclick="window.app.filterHistory('withdrawal')">Withdrawals</button>
                        <button class="filter-btn-zupee" data-filter="match_win" onclick="window.app.filterHistory('match_win')">Winnings</button>
                        <button class="filter-btn-zupee" data-filter="match_fee" onclick="window.app.filterHistory('match_fee')">Game Fees</button>
                    </div>
                    <div id="historyList" style="margin-top:12px;"><p style="color:#555555;text-align:center;padding:20px;font-weight:700;">Login to view history</p></div>
                </div>
            </section>
            <section id="page-refer" class="page">
                <div style="padding:16px;">
                    <div class="refer-hero-card" style="text-align:center;padding:24px;">
                        <div style="font-size:64px;">🎁</div>
                        <h2 style="color:#FFFFFF;font-size:24px;font-weight:800;" id="referHeroTitle">Refer & Earn ₹100</h2>
                        <p style="color:rgba(255,255,255,0.8);font-size:14px;margin-bottom:16px;font-weight:700;">Invite your friends — when they deposit a total of <strong style="color:#FFFFFF;" id="referThresholdText">₹500</strong>, you get <strong style="color:#FFFFFF;" id="referRewardText">₹100</strong>!</p>
                        <div class="refer-code-box" style="margin:16px 0;"><span id="referCodeText" style="font-size:22px;font-weight:800;color:#FFFFFF;letter-spacing:2px;">Loading...</span><button class="btn-copy" id="copyCodeBtn">📋 Copy</button></div>
                        <p style="color:rgba(255,255,255,0.8);font-size:13px;margin-bottom:8px;word-break:break-all;font-weight:700;" id="referLinkText"></p>
                        <div class="share-btns">
                            <button class="share-btn whatsapp" onclick="window.app.shareOn('whatsapp')">📱</button>
                            <button class="share-btn telegram" onclick="window.app.shareOn('telegram')">✈️</button>
                            <button class="share-btn copy" onclick="window.app.copyReferLink()">🔗</button>
                            <button class="share-btn native" onclick="window.app.nativeShare()" id="nativeShareBtn" style="display:none;">📤</button>
                        </div>
                    </div>
                    <div class="refer-stats-grid" id="referStatsGrid">
                        <div class="refer-stat-card"><div class="rs-value" id="statTotalReferrals">0</div><div class="rs-label">Total Referred</div></div>
                        <div class="refer-stat-card"><div class="rs-value" id="statTotalEarned">₹0</div><div class="rs-label">Total Earned</div></div>
                        <div class="refer-stat-card"><div class="rs-value" id="statPendingReferrals">0</div><div class="rs-label">Pending</div></div>
                    </div>
                    <div class="refer-rules-card">
                        <h3 class="section-title" style="margin-bottom:12px;color:#FFFFFF;">📋 How it Works</h3>
                        <div class="rule-row"><span>1️⃣</span><span>Share your referral code or link with a friend</span></div>
                        <div class="rule-row"><span>2️⃣</span><span>Your friend signs up and gets ₹<span id="ruleSignupBonus">5</span> instantly</span></div>
                        <div class="rule-row"><span>3️⃣</span><span>Your friend deposits money — small amounts are fine, they add up</span></div>
                        <div class="rule-row"><span>4️⃣</span><span>Once their <strong>total</strong> deposits reach <span id="ruleThreshold">₹500</span>, you get <span id="ruleReward">₹100</span> automatically</span></div>
                    </div>
                    <div class="refer-history-section">
                        <h3 class="section-title" style="margin:20px 0 12px;">👥 Your Referrals</h3>
                        <div id="referHistoryList"><div class="empty-msg">Loading...</div></div>
                    </div>
                </div>
            </section>
            <section id="page-profile" class="page">
                <div style="padding:16px;">
                    <div class="profile-header-card" style="position:relative;">
                        <div class="profile-avatar-zupee" style="position:relative;">G
                            <span id="kycAvatarBadge" class="kyc-avatar-badge" style="display:none;"></span>
                        </div>
                        <h3 id="profileName" style="color:#FFFFFF;font-weight:800;">Guest User</h3>
                        <span id="profileId" style="color:rgba(255,255,255,0.8);font-size:12px;font-weight:700;">ID: #GUEST001</span>
                        <div id="kycStatusPill" class="kyc-status-pill" style="display:none;"></div>
                    </div>
                    <div style="margin-top:12px;">
                        <label style="color:#000000;font-size:13px;font-weight:800;">🌐 Change Language</label>
                        <select class="lang-select-profile" id="langSelectProfile" onchange="window.app.changeLanguage(this.value)">
                            <option value="en">🇬🇧 English</option>
                            <option value="hi">🇮🇳 हिन्दी (Hindi)</option>
                            <option value="bn">🇧🇩 বাংলা (Bengali)</option>
                            <option value="ta">🇮🇳 தமிழ் (Tamil)</option>
                            <option value="te">🇮🇳 తెలుగు (Telugu)</option>
                            <option value="mr">🇮🇳 मराठी (Marathi)</option>
                            <option value="gu">🇮🇳 ગુજરાતી (Gujarati)</option>
                        </select>
                    </div>
                    <div class="profile-menu-zupee">
                        <button class="pm-item" id="loginBtnProfile">🚪 Login</button>
                        <button class="pm-item" id="registerBtnProfile">📝 Register</button>
                        <button class="pm-item" id="logoutBtnProfile" style="display:none;">🚪 Logout</button>
                        <button class="pm-item" id="kycBtn" onclick="window.location.href='<?php echo htmlspecialchars($basePath); ?>/kyc.php'">🛡️ <span data-lang="kyc">KYC Verification</span></button>
                        <button class="pm-item" id="bankBtn" onclick="window.app.showBankSection()">🏦 Bank / UPI Details</button>
                        <button class="pm-item" id="supportBtn" onclick="window.app.navigateTo('support')">📞 Customer Support</button>
                    </div>
                    <div id="bankSection" style="display:none;margin-top:12px;background:#7D02AB;padding:16px;border-radius:12px;">
                        <h4 style="color:#FFFFFF;margin-bottom:10px;font-weight:800;">🏦 Bank / UPI Details (For Withdrawal)</h4>
                        <div id="bankDocStatus" style="margin-bottom:10px;"></div>
                        <div class="bank-form">
                            <input type="text" id="bankAccountName" placeholder="Account Holder Name">
                            <input type="text" id="bankAccountNumber" placeholder="Bank Account Number">
                            <input type="text" id="bankIFSC" placeholder="IFSC Code (e.g., SBIN0001234)">
                            <input type="text" id="bankUPI" placeholder="UPI ID (e.g., name@upi)">
                            <button id="submitBankBtn" onclick="window.app.saveBankDetails()" style="width:100%;padding:12px;background:#FFFFFF;color:#7D02AB;border:none;border-radius:8px;font-weight:800;font-size:14px;cursor:pointer;font-family:'Roboto Slab',serif;">💾 Save Bank Details</button>
                        </div>
                    </div>
                </div>
            </section>
            <section id="page-support" class="page">
                <div style="padding:16px;">
                    <div style="background:#7D02AB;border-radius:12px;padding:24px;text-align:center;box-shadow:0 4px 15px rgba(125,2,171,0.15);">
                        <span style="font-size:48px;">📞</span>
                        <h3 style="color:#FFFFFF;font-size:20px;margin:8px 0;font-weight:800;">Customer Support</h3>
                        <p style="color:rgba(255,255,255,0.8);font-size:13px;font-weight:700;">We're here to help you 24/7</p>
                    </div>
                    <div style="margin-top:16px;display:flex;flex-direction:column;gap:10px;">
                        <div style="background:#7D02AB;padding:14px;border-radius:10px;display:flex;align-items:center;gap:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);"><span style="font-size:28px;">📧</span><div><strong style="color:#FFFFFF;font-weight:800;">Email Us</strong><p style="color:rgba(255,255,255,0.8);font-size:12px;font-weight:700;">support@zupeex.com</p></div></div>
                        <div style="background:#7D02AB;padding:14px;border-radius:10px;display:flex;align-items:center;gap:12px;box-shadow:0 4px 15px rgba(125,2,171,0.15);"><span style="font-size:28px;">📱</span><div><strong style="color:#FFFFFF;font-weight:800;">WhatsApp</strong><p style="color:rgba(255,255,255,0.8);font-size:12px;font-weight:700;">+91 99999 99999</p></div></div>
                    </div>
                </div>
            </section>
        </main>
        <nav class="bottom-nav-zupee" id="bottomNav">
            <button class="bn-item active" data-page="dashboard"><span class="bn-icon"><img src="<?php echo navIconUrl($basePath, 'nav-home.png'); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';"><svg style="display:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span><span class="bn-label" data-lang="home">Home</span></button>
            <button class="bn-item" data-page="wallet"><span class="bn-icon"><img src="<?php echo navIconUrl($basePath, 'nav-wallet.png'); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';"><svg style="display:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="17" cy="15" r="1.5"/></svg></span><span class="bn-label" data-lang="wallet">Wallet</span></button>
            <button class="bn-item bn-center" data-page="refer"><div class="bn-center-btn"><img src="<?php echo navIconUrl($basePath, 'nav-refer.png'); ?>" alt="" width="22" height="22" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';"><svg style="display:none" viewBox="0 0 24 24" fill="none" stroke="#7D02AB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><rect x="3" y="8" width="18" height="13" rx="1"/><path d="M12 8v13M3 12h18"/></svg></div><span class="bn-label" data-lang="refer">Refer</span></button>
            <button class="bn-item" data-page="history"><span class="bn-icon"><img src="<?php echo navIconUrl($basePath, 'nav-history.png'); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';"><svg style="display:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"/><path d="M3.05 13a9 9 0 1 0 .5-4.5L3 8"/><path d="M12 7v5l4 2"/></svg></span><span class="bn-label" data-lang="history">History</span></button>
            <button class="bn-item" data-page="profile"><span class="bn-icon"><img src="<?php echo navIconUrl($basePath, 'nav-profile.png'); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';"><svg style="display:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span><span class="bn-label" data-lang="profile">Profile</span></button>
        </nav>

        <div class="modal-overlay-zupee" id="authModal" style="background-image:url('<?php echo navIconUrl($basePath, 'auth-background.png'); ?>');background-size:cover;background-position:center;">
            <div class="modal-card-zupee auth-modal-card">
                <div class="modal-header-zupee">
                    <h2 id="authModalTitle">Welcome Back!</h2>
                    <button class="modal-close-zupee" id="authModalClose">✕</button>
                </div>
                <div class="modal-body-zupee">
                    <form id="loginForm" class="auth-form-zupee active">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="form-group-zupee"><label>Mobile / Username / Email</label><input type="text" id="loginMobile" placeholder="Enter mobile, username or email" required></div>
                        <div class="form-group-zupee"><label>Password</label><input type="password" id="loginPassword" placeholder="Enter your password" required minlength="6"></div>
                        <button type="submit" class="btn-auth-submit">Login</button>
                        <p class="auth-switch-text">Don't have an account? <a href="#" id="switchToRegister">Register</a></p>
                    </form>
                    <form id="registerForm" class="auth-form-zupee">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="form-group-zupee"><label>Username</label><input type="text" id="regUsername" placeholder="Choose a username" required minlength="3"></div>
                        <div class="form-group-zupee"><label>Mobile Number</label><input type="tel" id="regMobile" placeholder="10-digit mobile number" required maxlength="10"></div>
                        <div class="form-group-zupee"><label>Password</label><input type="password" id="regPassword" placeholder="Min 6 characters" required minlength="6"></div>
                        <div class="form-group-zupee checkbox-group"><input type="checkbox" id="regTerms" required><label for="regTerms">I agree to Terms & Conditions</label></div>
                        <div class="form-group-zupee"><label>Referral Code <span style="opacity:0.6;">(optional)</span></label><input type="text" id="regReferralCode" placeholder="Enter code if you have one" style="text-transform:uppercase;"></div>
                        <button type="submit" class="btn-auth-submit">Create Account</button>
                        <p class="auth-switch-text">Already have an account? <a href="#" id="switchToLogin">Login</a></p>
                    </form>
                </div>
            </div>
        </div>

        <!-- 🔥 PREMIUM ADD MONEY MODAL -->
        <div class="casino-modal-overlay" id="addMoneyModal">
            <div class="casino-modal-card">
                <div class="casino-modal-content">
                    <div class="casino-modal-header">
                        <div class="casino-header-row">
                            <div class="casino-header-title">
                                <div class="casino-header-icon">💰</div>
                                <div class="casino-header-text"><h2>Add Money</h2><p>Instant deposit via Cashfree</p></div>
                            </div>
                            <button class="casino-close-btn" id="addMoneyModalClose">✕</button>
                        </div>
                    </div>
                    <div class="casino-modal-body">
                        <div class="casino-balance-display">
                            <div class="casino-balance-label">Select Amount</div>
                            <div class="casino-balance-amount" id="addMoneySelectedAmount">₹0</div>
                        </div>
                        <div class="casino-quick-grid" id="addMoneyQuickGrid">
                            <?php foreach ([100,200,300,500,1000,2000,3000,5000,10000,30000,50000,100000,1000000] as $amt): ?>
                            <button type="button" class="casino-amount-btn" data-amount="<?php echo $amt; ?>">₹<?php echo number_format($amt); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="casino-input-label">Or Enter Custom Amount</div>
                        <input type="number" class="casino-input" id="addMoneyCustomAmount" placeholder="₹1 - ₹10,00,000" min="1" max="1000000">
                        <button type="button" class="casino-cta-btn" id="addMoneyProceedBtn">⚡ Add Money Instantly</button>
                        <div class="casino-security-badge"><span>🔒</span><span>Secured by Cashfree Payments • 256-bit SSL</span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 🔥 PREMIUM WITHDRAW MODAL -->
        <div class="casino-modal-overlay" id="withdrawModal">
            <div class="casino-modal-card">
                <div class="casino-modal-content">
                    <div class="casino-modal-header">
                        <div class="casino-header-row">
                            <div class="casino-header-title">
                                <div class="casino-header-icon">🏦</div>
                                <div class="casino-header-text"><h2>Withdraw</h2><p>Fast & secure payout</p></div>
                            </div>
                            <button class="casino-close-btn" id="withdrawModalClose">✕</button>
                        </div>
                    </div>
                    <div class="casino-modal-body">
                        <div class="casino-balance-display">
                            <div class="casino-balance-label">Available Balance</div>
                            <div class="casino-balance-amount" id="withdrawAvailBalance">₹0.00</div>
                        </div>
                        <div class="casino-info-box">⚡ Requires verified KYC + bound bank/UPI account</div>
                        <div class="casino-quick-grid" id="withdrawQuickGrid">
                            <?php foreach ([200,300,500,1000,2000,5000,10000,15000,20000,25000,30000,50000,100000] as $amt): ?>
                            <button type="button" class="casino-amount-btn" data-amount="<?php echo $amt; ?>">₹<?php echo number_format($amt); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="casino-input-label">Or Enter Custom Amount</div>
                        <input type="number" class="casino-input" id="withdrawCustomAmount" placeholder="₹1 - ₹1,00,000" min="1" max="100000">
                        <button type="button" class="casino-cta-btn" id="withdrawProceedBtn">💸 Request Withdrawal</button>
                        <div class="casino-security-badge"><span>🛡️</span><span>Protected & Secure Transaction</span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="toast-zupee" id="toast"><span id="toastMessage"></span></div>
    </div>

    <script src="<?php echo htmlspecialchars($basePath); ?>/assets/js/auth-helper.js"></script>
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>

    <script>
    (function() {
        'use strict';

        var LANG = {
            en: { home:'Home', wallet:'Wallet', refer:'Refer', history:'History', profile:'Profile', login:'Login', register:'Register', logout:'Logout', balance:'Available Balance', addCash:'+ Add Cash', withdraw:'Withdraw', tournament:'🎟️ Tournament Tickets', howToPlay:'📖 How to Play', referEarn:'Refer & Earn ₹100', kyc:'KYC Verification', bank:'Bank / UPI Details', support:'Customer Support' },
            hi: { home:'होम', wallet:'वॉलेट', refer:'रेफ़र', history:'इतिहास', profile:'प्रोफ़ाइल', login:'लॉगिन', register:'रजिस्टर', logout:'लॉगआउट', balance:'उपलब्ध बैलेंस', addCash:'+ पैसे जोड़ें', withdraw:'निकासी', tournament:'🎟️ टूर्नामेंट टिकट', howToPlay:'📖 कैसे खेलें', referEarn:'रेफ़र करें ₹100 कमाएं', kyc:'KYC सत्यापन', bank:'बैंक / UPI विवरण', support:'ग्राहक सहायता' }
        };
        var currentLang = localStorage.getItem('ludoLang') || 'en';

        function escapeHtml(str) { if (!str) return ''; var div = document.createElement('div'); div.textContent = str; return div.innerHTML; }

        function showToast(message, type) {
            type = type || 'info';
            var toast = document.getElementById('toast');
            var msg = document.getElementById('toastMessage');
            if (!toast || !msg) return;
            msg.textContent = message;
            toast.className = 'toast-zupee ' + type + ' show';
            if (window._toastTimer) clearTimeout(window._toastTimer);
            window._toastTimer = setTimeout(function() { toast.classList.remove('show'); }, 3000);
        }

        function LudoApp() {
            this.currentPage = 'dashboard';
            this.isLoggedIn = false;
            this.walletBalance = 0;
            this.userData = null;
            this.basePath = '<?php echo htmlspecialchars($basePath); ?>';
            this.csrfToken = '<?php echo htmlspecialchars($csrf_token); ?>';
            this.bannerIndex = 0;
            this.bannerInterval = null;
            this.historyFilter = 'all';
            this.allHistoryData = [];
            this.ticketMode = '1vs1';
            this.ticketPollInterval = null;
            this._referCode = '';
            this._referLink = '';
            this.init();
        }

        LudoApp.prototype.init = function() {
            this.bindNavigation();
            this.bindAuthEvents();
            this.bindWalletEvents();
            this.startBannerCarousel();
            this.checkAuthStatus();
            this.loadTicketsList();
            var self = this;
            var urlParams = new URLSearchParams(window.location.search);
            var refFromUrl = urlParams.get('ref');
            if (refFromUrl) {
                var refInput = document.getElementById('regReferralCode');
                if (refInput) { refInput.value = refFromUrl.toUpperCase(); refInput.readOnly = true; }
            }
            this.applyLanguage();
        };

        LudoApp.prototype.changeLanguage = function(lang) { currentLang = lang; this.applyLanguage(); localStorage.setItem('ludoLang', lang); };

        LudoApp.prototype.applyLanguage = function() {
            var t = LANG[currentLang] || LANG.en;
            document.querySelectorAll('[data-lang]').forEach(function(el) { var key = el.getAttribute('data-lang'); if (t[key]) el.textContent = t[key]; });
            var langSelect = document.getElementById('langSelectProfile');
            if (langSelect) langSelect.value = currentLang;
        };

        LudoApp.prototype.startBannerCarousel = function() {
            var track = document.getElementById('bannerTrack');
            var dots = document.querySelectorAll('#bannerDots .dot');
            if (!track || !dots.length) return;
            var total = 4;
            var self = this;
            function updateBanner(index) { track.style.transform = 'translateX(-' + (index * 100) + '%)'; dots.forEach(function(d, j) { d.classList.toggle('active', j === index); }); }
            this.bannerInterval = setInterval(function() { self.bannerIndex = (self.bannerIndex + 1) % total; updateBanner(self.bannerIndex); }, 4000);
            dots.forEach(function(d) { d.addEventListener('click', function() { self.bannerIndex = parseInt(this.getAttribute('data-index')); updateBanner(self.bannerIndex); }); });
        };

        LudoApp.prototype.bindNavigation = function() {
            var self = this;
            document.querySelectorAll('.bn-item').forEach(function(item) { item.addEventListener('click', function() { self.navigateTo(this.getAttribute('data-page')); }); });
            document.getElementById('headerWalletBtn').addEventListener('click', function() { self.navigateTo('wallet'); });
        };

        LudoApp.prototype.navigateTo = function(page) {
            document.querySelectorAll('.page').forEach(function(p) { p.classList.remove('active'); });
            var target = document.getElementById('page-' + page);
            if (target) { target.classList.add('active'); this.currentPage = page; }
            document.querySelectorAll('.bn-item').forEach(function(n) { n.classList.remove('active'); });
            var navItem = document.querySelector('.bn-item[data-page="' + page + '"]');
            if (navItem) navItem.classList.add('active');
            if (page === 'wallet') { this.fetchWalletBalance(); this.fetchWalletTransactions(); }
            if (page === 'history') this.fetchCompleteHistory();
            if (page === 'refer') this.loadReferralData();
            if (page === 'dashboard') { this.fetchWalletBalance(); this.loadTicketsList(); }
        };

        LudoApp.prototype.bindAuthEvents = function() {
            var self = this;
            document.getElementById('loginBtnProfile').addEventListener('click', function() { self.openAuthModal('login'); });
            document.getElementById('registerBtnProfile').addEventListener('click', function() { self.openAuthModal('register'); });
            document.getElementById('headerLoginBtn').addEventListener('click', function() { self.openAuthModal('login'); });
            document.getElementById('authModalClose').addEventListener('click', function() { self.closeAuthModal(); });
            document.getElementById('switchToRegister').addEventListener('click', function(e) { e.preventDefault(); self.openAuthModal('register'); });
            document.getElementById('switchToLogin').addEventListener('click', function(e) { e.preventDefault(); self.openAuthModal('login'); });
            document.getElementById('loginForm').addEventListener('submit', function(e) { e.preventDefault(); self.handleLogin(); });
            document.getElementById('registerForm').addEventListener('submit', function(e) { e.preventDefault(); self.handleRegister(); });
            document.getElementById('logoutBtnProfile').addEventListener('click', function() { self.handleLogout(); });
        };

        LudoApp.prototype.openAuthModal = function(type) {
            document.getElementById('authModalTitle').textContent = (type === 'login') ? 'Welcome Back!' : 'Create Account';
            document.getElementById('loginForm').classList.toggle('active', type === 'login');
            document.getElementById('registerForm').classList.toggle('active', type === 'register');
            document.getElementById('authModal').classList.add('active');
        };
        LudoApp.prototype.closeAuthModal = function() { document.getElementById('authModal').classList.remove('active'); };

        LudoApp.prototype.handleLogin = async function() {
            var username = document.getElementById('loginMobile').value.trim();
            var password = document.getElementById('loginPassword').value;
            if (!username || password.length < 6) { showToast('Please fill all fields correctly', 'error'); return; }
            var result = await AuthHelper.login({ username: username, password: password });
            if (result.success) {
                this.isLoggedIn = true;
                this.userData = (result.data && result.data.user) ? result.data.user : (result.data || null);
                if (result.data && result.data.csrf_token) { this.csrfToken = result.data.csrf_token; localStorage.setItem('csrf_token', result.data.csrf_token); }
                this.updateUI();
                this.closeAuthModal();
                this.fetchWalletBalance();
                showToast('✅ Login successful!', 'success');
            } else { showToast('❌ ' + (result.message || 'Login failed'), 'error'); }
        };

        LudoApp.prototype.handleRegister = async function() {
            if (!document.getElementById('regTerms').checked) { showToast('Please accept Terms & Conditions', 'error'); return; }
            var data = { username: document.getElementById('regUsername').value.trim(), mobile: document.getElementById('regMobile').value.trim(), password: document.getElementById('regPassword').value, referral_code: document.getElementById('regReferralCode').value.trim() };
            if (!data.username || !data.mobile || data.password.length < 6) { showToast('Please fill all required fields', 'error'); return; }
            var result = await AuthHelper.register(data);
            if (result.success) {
                this.isLoggedIn = true;
                this.userData = (result.data && result.data.user) ? result.data.user : (result.data || null);
                if (result.data && result.data.csrf_token) { this.csrfToken = result.data.csrf_token; localStorage.setItem('csrf_token', result.data.csrf_token); }
                this.updateUI();
                this.closeAuthModal();
                this.fetchWalletBalance();
                showToast('✅ Registration successful!', 'success');
            } else { showToast('❌ ' + (result.message || 'Failed'), 'error'); }
        };

        LudoApp.prototype.handleLogout = async function() {
            if (!confirm('Are you sure you want to logout?')) return;
            await AuthHelper.logout();
            this.isLoggedIn = false; this.userData = null; this.walletBalance = 0;
            this.updateUI();
            showToast('Logged out successfully', 'info');
        };

        LudoApp.prototype.checkAuthStatus = async function() {
            var result = await AuthHelper.checkAuth();
            if (result.success && result.isLoggedIn) {
                this.isLoggedIn = true;
                this.userData = result.user || (result.data && result.data.user) || null;
                if (result.data && result.data.csrf_token) { this.csrfToken = result.data.csrf_token; localStorage.setItem('csrf_token', result.data.csrf_token); }
                this.updateUI();
                this.fetchWalletBalance();
                this.checkExistingTicketQueue();
            }
        };

        LudoApp.prototype.fetchWalletBalance = async function() {
            if (!this.isLoggedIn) return;
            try {
                var data = await AuthHelper.request(
                    this.basePath + '/api/wallet.php?action=balance',
                    'GET',
                    {}
                );
                
                if (data.success && data.data) {
                    this.walletBalance = parseFloat(data.data.balance || 0);
                    document.getElementById('headerBalance').textContent = '₹' + this.walletBalance.toFixed(0);
                    var wl = document.getElementById('walletLarge'); 
                    if (wl) wl.textContent = '₹' + this.walletBalance.toFixed(2);
                } else if (data.message && data.message.includes('login')) {
                    this.isLoggedIn = false;
                    this.userData = null;
                    this.updateUI();
                }
            } catch (e) {
                console.warn('[Wallet] Error:', e);
            }
        };

        LudoApp.prototype.openAddMoneyModal = function() { document.getElementById('addMoneyModal').classList.add('active'); };
        LudoApp.prototype.closeAddMoneyModal = function() { document.getElementById('addMoneyModal').classList.remove('active'); };
        LudoApp.prototype.selectQuickAmount = function(gridId, btn, customInputId) {
            document.querySelectorAll('#' + gridId + ' .casino-amount-btn').forEach(function(b) { b.classList.remove('selected'); });
            btn.classList.add('selected');
            document.getElementById(customInputId).value = btn.getAttribute('data-amount');
            if (gridId === 'addMoneyQuickGrid') {
                document.getElementById('addMoneySelectedAmount').textContent = '₹' + parseFloat(btn.getAttribute('data-amount')).toLocaleString();
            }
        };
        LudoApp.prototype.proceedAddMoney = async function() {
            var self = this;
            var amount = parseFloat(document.getElementById('addMoneyCustomAmount').value || '0');
            if (!amount || amount < 1 || amount > 1000000) { showToast('Enter a valid amount between ₹1 and ₹10,00,000', 'error'); return; }
            var btn = document.getElementById('addMoneyProceedBtn');
            btn.disabled = true; btn.textContent = 'Creating order...';
            try {
                var csrf = this.csrfToken || '';
                var returnUrl = window.location.origin + window.location.pathname + '?wallet_return=1';
                var res = await fetch(this.basePath + '/api/cashfree.php?action=create_order', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ amount: amount, return_url: returnUrl, csrf_token: csrf }) });
                var data = await res.json();
                if (!data.success) { showToast(data.message || 'Could not create payment order', 'error'); btn.disabled = false; btn.textContent = 'Add Money'; return; }
                var sessionId = data.data.payment_session_id;
                if (typeof Cashfree === 'undefined' || !sessionId) { showToast('Payment gateway failed to load', 'error'); btn.disabled = false; btn.textContent = 'Add Money'; return; }
                var cashfree = Cashfree({ mode: '<?php echo (CASHFREE_ENV_CFG === "production") ? "production" : "sandbox"; ?>' });
                cashfree.checkout({ paymentSessionId: sessionId, redirectTarget: '_modal' }).then(function() { self.verifyAndCreditDeposit(data.data.order_id, btn); }).catch(function() { showToast('Payment could not be completed', 'error'); btn.disabled = false; btn.textContent = 'Add Money'; });
            } catch (e) { showToast('Network error', 'error'); btn.disabled = false; btn.textContent = 'Add Money'; }
        };
        LudoApp.prototype.verifyAndCreditDeposit = async function(orderId, btn) {
            var self = this;
            try {
                var csrf = this.csrfToken || '';
                var res = await fetch(this.basePath + '/api/cashfree.php?action=verify_payment', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ order_id: orderId, csrf_token: csrf }) });
                var data = await res.json();
                if (data.success && data.data.status === 'success') {
                    showToast('✅ Payment added to wallet!', 'success');
                    self.closeAddMoneyModal();
                    self.fetchWalletBalance();
                    self.fetchWalletTransactions();
                } else { showToast('Payment verification pending', 'info'); self.closeAddMoneyModal(); }
            } catch (e) { showToast('Could not verify payment', 'error'); }
            if (btn) { btn.disabled = false; btn.textContent = 'Add Money'; }
        };

        LudoApp.prototype.openWithdrawModal = function() { document.getElementById('withdrawModal').classList.add('active'); document.getElementById('withdrawAvailBalance').textContent = '₹' + (this.walletBalance || 0).toFixed(2); };
        LudoApp.prototype.closeWithdrawModal = function() { document.getElementById('withdrawModal').classList.remove('active'); };
        LudoApp.prototype.proceedWithdraw = async function() {
            var self = this;
            var amount = parseFloat(document.getElementById('withdrawCustomAmount').value || '0');
            if (!amount || amount < 1 || amount > 100000) { showToast('Enter a valid amount between ₹1 and ₹1,00,000', 'error'); return; }
            var btn = document.getElementById('withdrawProceedBtn');
            btn.disabled = true; btn.textContent = 'Submitting...';
            try {
                var csrf = this.csrfToken || '';
                var res = await fetch(this.basePath + '/api/wallet.php?action=withdraw', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ amount: amount, csrf_token: csrf }) });
                var data = await res.json();
                if (data.success) { showToast('✅ Withdrawal request submitted', 'success'); self.closeWithdrawModal(); self.fetchWalletBalance(); }
                else { showToast(data.message || 'Withdrawal failed', 'error'); }
            } catch (e) { showToast('Network error', 'error'); }
            btn.disabled = false; btn.textContent = 'Request Withdrawal';
        };

        LudoApp.prototype.fetchWalletTransactions = async function() {
            if (!this.isLoggedIn) return;
            try {
                var res = await fetch(this.basePath + '/api/wallet.php?action=history&limit=20', { credentials: 'include' });
                var data = await res.json();
                var container = document.getElementById('walletTransactions');
                if (!container) return;
                if (data.success && data.data.transactions && data.data.transactions.length > 0) {
                    var html = '<h4 style="color:#000000;margin-bottom:10px;font-weight:800;">Recent Transactions</h4>';
                    data.data.transactions.forEach(function(tx) { var sign = tx.type === 'credit' ? '+' : '-'; var color = tx.type === 'credit' ? '#047857' : '#B91C1C'; html += '<div style="background:#FFFFFF;padding:10px 14px;border-radius:8px;margin-bottom:6px;display:flex;justify-content:space-between;box-shadow:0 2px 8px rgba(125,2,171,0.1);"><span style="color:#000000;font-size:13px;font-weight:700;">' + escapeHtml(tx.description || 'Transaction') + '</span><span style="color:' + color + ';font-weight:800;">' + sign + '₹' + parseFloat(tx.amount).toFixed(2) + '</span></div>'; });
                    container.innerHTML = html;
                } else { container.innerHTML = '<p style="color:#555555;text-align:center;padding:20px;font-weight:700;">No transactions yet</p>'; }
            } catch (e) {}
        };

        LudoApp.prototype.bindWalletEvents = function() {
            var self = this;
            document.getElementById('addMoneyBtn').addEventListener('click', function() { if (!self.isLoggedIn) { showToast('Please login first', 'error'); self.openAuthModal('login'); return; } self.openAddMoneyModal(); });
            document.getElementById('withdrawBtn').addEventListener('click', function() { if (!self.isLoggedIn) { showToast('Please login first', 'error'); self.openAuthModal('login'); return; } self.openWithdrawModal(); });
            document.getElementById('addMoneyModalClose').addEventListener('click', function() { self.closeAddMoneyModal(); });
            document.getElementById('withdrawModalClose').addEventListener('click', function() { self.closeWithdrawModal(); });
            document.getElementById('addMoneyQuickGrid').addEventListener('click', function(e) { var b = e.target.closest('.casino-amount-btn'); if (!b) return; self.selectQuickAmount('addMoneyQuickGrid', b, 'addMoneyCustomAmount'); });
            document.getElementById('withdrawQuickGrid').addEventListener('click', function(e) { var b = e.target.closest('.casino-amount-btn'); if (!b) return; self.selectQuickAmount('withdrawQuickGrid', b, 'withdrawCustomAmount'); });
            document.getElementById('addMoneyProceedBtn').addEventListener('click', function() { self.proceedAddMoney(); });
            document.getElementById('withdrawProceedBtn').addEventListener('click', function() { self.proceedWithdraw(); });
            document.getElementById('copyCodeBtn').addEventListener('click', function() { if (!self._referCode) { showToast('Loading...', 'info'); return; } navigator.clipboard.writeText(self._referCode).then(function() { showToast('✅ Code copied!', 'success'); }); });
        };

        LudoApp.prototype.fetchCompleteHistory = async function() {
            if (!this.isLoggedIn) { document.getElementById('historyList').innerHTML = '<p style="color:#555555;text-align:center;padding:40px;font-weight:700;">Please login to view history</p>'; return; }
            try {
                var txRes = await fetch(this.basePath + '/api/wallet.php?action=history&limit=50', { credentials: 'include' });
                var txData = await txRes.json();
                var allItems = [];
                if (txData.success && txData.data.transactions) { txData.data.transactions.forEach(function(tx) { allItems.push({ title: tx.description || 'Transaction', amount: parseFloat(tx.amount), isCredit: tx.type === 'credit', date: tx.created_at, category: tx.source }); }); }
                allItems.sort(function(a, b) { return new Date(b.date) - new Date(a.date); });
                this.allHistoryData = allItems;
                this.renderHistory(allItems);
            } catch (e) { document.getElementById('historyList').innerHTML = '<p style="color:#B91C1C;text-align:center;font-weight:700;">Error loading history</p>'; }
        };

        LudoApp.prototype.renderHistory = function(items) {
            var container = document.getElementById('historyList');
            if (!items || items.length === 0) { container.innerHTML = '<p style="color:#555555;text-align:center;padding:40px;font-weight:700;">📭 No history yet</p>'; return; }
            var icons = { deposit:'💰', withdrawal:'🏦', match_win:'🏆', match_fee:'🎲', bonus:'🎁', refund:'↩️' };
            var html = '';
            items.forEach(function(item) { var icon = icons[item.category] || '💳'; var sign = item.isCredit ? '+' : '-'; var color = item.isCredit ? '#047857' : '#B91C1C'; html += '<div style="background:#FFFFFF;padding:12px 14px;border-radius:10px;margin-bottom:8px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(125,2,171,0.1);"><div style="font-size:24px;">' + icon + '</div><div style="flex:1;"><span style="color:#000000;font-size:14px;font-weight:800;">' + escapeHtml(item.title) + '</span></div><span style="color:' + color + ';font-weight:800;">' + sign + '₹' + item.amount.toFixed(2) + '</span></div>'; });
            container.innerHTML = html;
        };

        LudoApp.prototype.filterHistory = function(filter) {
            this.historyFilter = filter;
            document.querySelectorAll('.filter-btn-zupee').forEach(function(b) { b.classList.remove('active'); });
            var activeBtn = document.querySelector('.filter-btn-zupee[data-filter="' + filter + '"]'); if (activeBtn) activeBtn.classList.add('active');
            if (filter === 'all') { this.renderHistory(this.allHistoryData); }
            else { this.renderHistory(this.allHistoryData.filter(function(i) { return i.category === filter; })); }
        };

        LudoApp.prototype.switchTicketMode = function(mode) { this.ticketMode = mode; document.querySelectorAll('.tmt-btn').forEach(function(b) { b.classList.toggle('active', b.getAttribute('data-mode') === mode); }); this.loadTicketsList(); };

        LudoApp.prototype.loadTicketsList = async function() {
            var self = this;
            var grid = document.getElementById('ticketGrid');
            if (!grid) return;
            
            grid.innerHTML = '<div class="empty-msg">Loading tickets...</div>';
            
            try {
                var data = await AuthHelper.request(
                    this.basePath + '/api/tickets.php?action=list',
                    'GET',
                    {}
                );
                
                console.log('[Tickets] Response:', data);
                
                if (!data.success) { 
                    grid.innerHTML = '<div class="empty-msg">Could not load tickets. Please refresh.</div>'; 
                    return; 
                }
                
                var list = data.data.tickets[self.ticketMode] || [];
                if (list.length === 0) { 
                    grid.innerHTML = '<div class="empty-msg">No tickets available</div>'; 
                    return; 
                }
                
                grid.innerHTML = list.map(function(t) { 
                    return '<div class="tournament-card-zupee" onclick="window.app.joinTicket(' + t.id + ')">' +
                        '<div class="tcz-header">' +
                            '<span class="tcz-badge">Entry ₹' + t.entry_fee.toFixed(0) + '</span>' +
                        '</div>' +
                        '<div class="tcz-body">' +
                            '<div class="tcz-prize-row">' +
                                '<span class="tcz-prize-amount">Win ₹' + t.winner_payout.toFixed(0) + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>'; 
                }).join('');
            } catch (e) { 
                console.error('[Tickets] Error:', e);
                grid.innerHTML = '<div class="empty-msg">Network error. Please refresh.</div>'; 
            }
        };

        LudoApp.prototype.joinTicket = async function(ticketId) {
            if (!this.isLoggedIn) { 
                showToast('Please login first', 'error'); 
                this.openAuthModal('login'); 
                return; 
            }
            var self = this;
            
            try {
                var data = await AuthHelper.request(
                    this.basePath + '/api/tickets.php?action=join',
                    'POST',
                    { ticket_id: ticketId }
                );
                
                console.log('[Join] Response:', data);
                
                if (!data.success) { 
                    showToast(data.message || 'Could not join', 'error'); 
                    return; 
                }
                
                if (data.data.status === 'matched') { 
                    showToast('✅ Match found!', 'success'); 
                    setTimeout(function() { 
                        window.location.href = self.basePath + '/game.php?match_id=' + data.data.match_id; 
                    }, 1000); 
                } else { 
                    document.getElementById('ticketWaitingModal').classList.add('active'); 
                    document.getElementById('ticketWaitingCount').textContent = 'Waiting (' + data.data.waiting_count + '/' + data.data.players_needed + ')'; 
                    this.startTicketPolling(); 
                }
            } catch (e) { 
                console.error('[Join] Error:', e);
                showToast('Network error', 'error'); 
            }
        };

        LudoApp.prototype.checkExistingTicketQueue = async function() {
            if (!this.isLoggedIn) return;
            var self = this;
            try {
                var res = await fetch(this.basePath + '/api/tickets.php?action=status', { credentials: 'include' });
                var data = await res.json();
                if (data.success && data.data.status === 'waiting') { document.getElementById('ticketWaitingModal').classList.add('active'); this.startTicketPolling(); }
                else if (data.success && data.data.status === 'matched') { window.location.href = self.basePath + '/game.php?match_id=' + data.data.match_id; }
            } catch (e) {}
        };

        LudoApp.prototype.startTicketPolling = function() {
            var self = this;
            if (this.ticketPollInterval) clearInterval(this.ticketPollInterval);
            this.ticketPollInterval = setInterval(async function() {
                try {
                    var res = await fetch(self.basePath + '/api/tickets.php?action=status', { credentials: 'include' });
                    var data = await res.json();
                    if (data.success && data.data.status === 'matched') { 
                        clearInterval(self.ticketPollInterval); 
                        window.location.href = self.basePath + '/game.php?match_id=' + data.data.match_id; 
                    } else if (data.success && data.data.status === 'waiting') { 
                        document.getElementById('ticketWaitingCount').textContent = 'Waiting (' + data.data.waiting_count + '/' + data.data.players_needed + ')'; 
                    } else { 
                        clearInterval(self.ticketPollInterval); 
                        document.getElementById('ticketWaitingModal').classList.remove('active'); 
                    }
                } catch (e) {}
            }, 2500);
        };

        LudoApp.prototype.cancelTicketQueue = async function(retryCount) {
            var self = this;
            
            if (retryCount === undefined) retryCount = 0;
            if (retryCount > 2) {
                console.log('[Cancel] Max retries reached.');
                showToast('Unable to cancel. Please refresh the page.', 'error');
                if (this.ticketPollInterval) {
                    clearInterval(this.ticketPollInterval);
                    this.ticketPollInterval = null;
                }
                document.getElementById('ticketWaitingModal').classList.remove('active');
                return;
            }
            
            var btn = document.querySelector('#ticketWaitingModal .btn-auth-submit');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Cancelling...';
            }
            
            try {
                var data = await AuthHelper.request(
                    this.basePath + '/api/tickets.php?action=cancel',
                    'POST',
                    {}
                );
                
                console.log('[Cancel] Response:', data);
                
                if (!data.success && data.data && data.data.refresh_needed && retryCount < 2) {
                    showToast('Token refreshed. Trying again...', 'info');
                    setTimeout(function() { self.cancelTicketQueue(retryCount + 1); }, 500);
                    if (btn) { btn.disabled = false; btn.textContent = 'Cancel & Refund'; }
                    return;
                }
                
                if (this.ticketPollInterval) { clearInterval(this.ticketPollInterval); this.ticketPollInterval = null; }
                document.getElementById('ticketWaitingModal').classList.remove('active');
                
                if (data.success) {
                    var refundAmount = data.data.refund_amount || 0;
                    showToast('✅ Cancelled! Refunded ₹' + refundAmount.toFixed(2), 'success');
                    setTimeout(function() { self.fetchWalletBalance(); self.fetchWalletTransactions(); self.loadTicketsList(); }, 500);
                } else {
                    showToast(data.message || 'Could not cancel', 'error');
                }
            } catch (e) {
                console.error('[Cancel] Error:', e);
                showToast('Network error. Please refresh the page.', 'error');
                document.getElementById('ticketWaitingModal').classList.remove('active');
            }
            
            if (btn) { btn.disabled = false; btn.textContent = 'Cancel & Refund'; }
        };

        LudoApp.prototype.updateUI = function() {
            var loggedIn = this.isLoggedIn;
            document.getElementById('loginBtnProfile').style.display = loggedIn ? 'none' : 'flex';
            document.getElementById('registerBtnProfile').style.display = loggedIn ? 'none' : 'flex';
            document.getElementById('logoutBtnProfile').style.display = loggedIn ? 'flex' : 'none';
            document.getElementById('headerLoginBtn').style.display = loggedIn ? 'none' : 'inline-block';
            if (this.userData) { document.getElementById('profileName').textContent = this.userData.username || 'Player'; }
        };

        LudoApp.prototype.showBankSection = function() { var s = document.getElementById('bankSection'); if (s) s.style.display = (s.style.display === 'none' || s.style.display === '') ? 'block' : 'none'; };

        LudoApp.prototype.saveBankDetails = async function() {
            if (!this.isLoggedIn) { showToast('Please login first', 'error'); return; }
            var name = document.getElementById('bankAccountName').value.trim();
            var acct = document.getElementById('bankAccountNumber').value.trim();
            var ifsc = document.getElementById('bankIFSC').value.trim();
            var upi = document.getElementById('bankUPI').value.trim();
            if (!upi && (!name || !acct || !ifsc)) { showToast('Fill bank details or UPI ID', 'error'); return; }
            var btn = document.getElementById('submitBankBtn');
            btn.disabled = true; btn.textContent = '⏳ Saving...';
            try {
                var csrf = await AuthHelper.getCsrfToken();
                var fd = new FormData();
                fd.append('document_type', 'bank');
                fd.append('bank_account_name', name);
                fd.append('bank_account_number', acct);
                fd.append('bank_ifsc', ifsc);
                fd.append('upi_id', upi);
                fd.append('csrf_token', csrf);
                var res = await fetch(this.basePath + '/api/kyc.php?action=submit', { method: 'POST', credentials: 'include', headers: { 'X-CSRF-Token': csrf }, body: fd });
                var data = await res.json();
                showToast(data.success ? '✅ Saved!' : '❌ ' + (data.message || 'Failed'), data.success ? 'success' : 'error');
            } catch (e) { showToast('Network error', 'error'); }
            btn.disabled = false; btn.textContent = '💾 Save Bank Details';
        };

        LudoApp.prototype.loadReferralData = async function() {
            if (!this.isLoggedIn) return;
            var self = this;
            try {
                var res = await fetch(this.basePath + '/api/referral.php?action=get_all', { credentials: 'include' });
                var data = await res.json();
                if (!data.success) return;
                self._referCode = data.data.info.referral_code || '';
                self._referLink = data.data.info.referral_link || '';
                document.getElementById('referCodeText').textContent = self._referCode || '—';
                document.getElementById('referLinkText').textContent = self._referLink;
                document.getElementById('statTotalReferrals').textContent = data.data.stats.total_referrals;
                document.getElementById('statTotalEarned').textContent = '₹' + data.data.stats.total_earned.toFixed(0);
                document.getElementById('statPendingReferrals').textContent = data.data.stats.pending_referrals;
                var labels = { complete: '✅ Complete', in_progress: '⏳ In Progress', not_started: '❌ Not Started' };
                document.getElementById('referHistoryList').innerHTML = (data.data.history || []).map(function(r) { return '<div class="referral-item"><div class="ri-top"><span class="ri-name" style="color:#FFFFFF;">' + escapeHtml(r.username) + '</span><span class="ri-status ' + r.status + '">' + labels[r.status] + '</span></div><div class="ri-progress-track"><div class="ri-progress-fill" style="width:' + r.progress_percent + '%;"></div></div><div class="ri-progress-text">₹' + r.total_deposited.toFixed(0) + ' / ₹' + r.threshold.toFixed(0) + '</div></div>'; }).join('') || '<div class="empty-msg">No referrals yet</div>';
                if (navigator.share) document.getElementById('nativeShareBtn').style.display = 'inline-flex';
            } catch (e) {}
        };

        LudoApp.prototype.shareOn = function(platform) {
            if (!this._referCode) { showToast('Loading...', 'info'); return; }
            var text = encodeURIComponent('Play Zupeex, get ₹5 FREE! Use my code: ' + this._referCode + '. Link: ' + this._referLink);
            var links = { whatsapp: 'https://wa.me/?text=' + text, telegram: 'https://t.me/share/url?url=' + encodeURIComponent(this._referLink) + '&text=' + text };
            if (links[platform]) window.open(links[platform], '_blank');
        };
        LudoApp.prototype.copyReferLink = function() {
            if (!this._referLink) { showToast('Loading...', 'info'); return; }
            navigator.clipboard.writeText(this._referLink).then(function() { showToast('✅ Link copied!', 'success'); });
        };
        LudoApp.prototype.nativeShare = function() {
            if (!this._referCode) return;
            if (navigator.share) navigator.share({ title: 'Zupeex', text: 'Play Zupeex! Use code: ' + this._referCode, url: this._referLink });
        };

        document.addEventListener('DOMContentLoaded', function() { window.app = new LudoApp(); });
    })();
    </script>
</body>
</html>