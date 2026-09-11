/**
 * PWA-INSTALLER.JS - PWA Install Handler (FIXED)
 * Version: 3.1.0 - ROOT DEPLOYMENT FIX
 */

class PWAInstaller {
    constructor() {
        this.deferredPrompt = null;
        this.isInstalled = false;
        this.init();
    }

    init() {
        this.checkInstalled();
        window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); this.deferredPrompt = e; this.showInstallPromotion(); });
        window.addEventListener('appinstalled', () => { this.isInstalled = true; this.hideInstallPromotion(); this.showToast('🎉 App installed!', 'success'); });
        this.detectIOS();
        
        // ROOT FIX: Service worker path for root deployment
        if ('serviceWorker' in navigator) {
            const swPath = this.getServiceWorkerPath();
            navigator.serviceWorker.register(swPath).then(r => console.log('✅ SW registered:', r.scope)).catch(e => console.error('❌ SW failed:', e));
        }
    }

    getServiceWorkerPath() {
        const origin = window.location.origin;
        const path = window.location.pathname || '/';
        const dir = path.substring(0, path.lastIndexOf('/')) || '';
        if (dir === '' || dir === '/') {
            return origin + '/service-worker.js';
        }
        return origin + dir + '/service-worker.js';
    }

    checkInstalled() {
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) this.isInstalled = true;
    }

    detectIOS() {
        if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !this.isInstalled && !localStorage.getItem('ios_dismissed')) {
            setTimeout(() => this.showIOSInstructions(), 3000);
        }
    }

    showInstallPromotion() {
        if (this.isInstalled || document.getElementById('install-promotion')) return;
        const div = document.createElement('div');
        div.id = 'install-promotion';
        div.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#7D02AB;padding:16px 20px;border-radius:12px;z-index:999;max-width:90%;display:flex;align-items:center;gap:12px;box-shadow:0 8px 40px rgba(0,0,0,0.2)';
        div.innerHTML = `<div style="font-size:28px">📱</div><div><div style="font-weight:800;color:#FFFFFF">Install Zupeex</div><div style="font-size:12px;color:rgba(255,255,255,0.8);font-weight:700">Play anytime!</div></div><button id="install-btn" style="padding:8px 16px;border:none;border-radius:8px;background:#FFFFFF;color:#7D02AB;font-weight:800;cursor:pointer">Install</button>`;
        document.body.appendChild(div);
        document.getElementById('install-btn').addEventListener('click', () => this.installApp());
    }

    installApp() {
        if (!this.deferredPrompt) return;
        this.deferredPrompt.prompt();
        this.deferredPrompt.userChoice.then(r => {
            if (r.outcome === 'accepted') { this.isInstalled = true; this.hideInstallPromotion(); }
            this.deferredPrompt = null;
        });
    }

    hideInstallPromotion() { const el = document.getElementById('install-promotion'); if (el) { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); } }

    showIOSInstructions() {
        const div = document.createElement('div');
        div.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#FFFFFF;padding:20px;border-radius:12px;border:1px solid rgba(125,2,171,0.15);z-index:999;max-width:90%;box-shadow:0 8px 40px rgba(0,0,0,0.15)';
        div.innerHTML = `<div style="text-align:center"><div style="font-size:40px">📱</div><div style="font-weight:800;color:#000000;margin-top:8px">Install Zupeex</div><p style="font-size:13px;color:#555555;font-weight:700">1. Tap Share<br>2. Add to Home Screen<br>3. Tap Add</p><button id="dismiss-ios" style="width:100%;padding:10px;border:none;border-radius:8px;background:#F5E6FF;color:#000000;margin-top:8px;cursor:pointer;font-weight:800">Dismiss</button></div>`;
        document.body.appendChild(div);
        document.getElementById('dismiss-ios').addEventListener('click', () => { div.remove(); localStorage.setItem('ios_dismissed', 'true'); });
    }

    showToast(msg, type = 'info') {
        if (window.app?.showToast) { window.app.showToast(msg, type); return; }
        const t = document.createElement('div');
        const c = { success: 'rgba(16,185,129,0.2)', error: 'rgba(239,68,68,0.2)', info: 'rgba(125,2,171,0.2)' };
        t.style.cssText = `position:fixed;bottom:100px;left:50%;transform:translateX(-50%);padding:12px 24px;border-radius:12px;font-weight:800;font-size:14px;z-index:9999;background:${c[type]||c.info};color:#000000;max-width:90%`;
        t.textContent = msg; document.body.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 4000);
    }
}

document.addEventListener('DOMContentLoaded', () => { window.pwaInstaller = new PWAInstaller(); });
console.log('📱 PWA Installer v3.1 ready');