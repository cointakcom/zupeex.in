/**
 * INVITE-SYSTEM.JS - Complete Invite System (CSRF FIXED)
 * Version: 2.3.0 - CSRF AUTO-SYNC INTEGRATION
 */

class InviteSystem {
    constructor() {
        this.inviteCode = null;
        this.roomCode = null;
        this.matchId = null;
        this.userId = null;
        this.apiBase = this.getApiBase();
        this.init();
    }

    getApiBase() {
        const origin = window.location.origin;
        const path = window.location.pathname || '/';
        const dir = path.substring(0, path.lastIndexOf('/')) || '';
        if (dir === '' || dir === '/') {
            return origin + '/api/invite.php';
        }
        return origin + dir + '/api/invite.php';
    }

    init() {
        this.userId = window.app?.userData?.id || localStorage.getItem('userId') || null;
        this.loadInviteData();
        this.bindEvents();
    }

    loadInviteData() {
        const params = new URLSearchParams(window.location.search);
        this.roomCode = params.get('room') || params.get('room_code') || null;
        if (this.roomCode) this.handleInviteLink();
    }

    bindEvents() {
        document.getElementById('shareReferBtn')?.addEventListener('click', () => this.shareInvite());
        document.getElementById('copyCodeBtn')?.addEventListener('click', () => this.copyInviteCode());
        document.getElementById('referralBtn')?.addEventListener('click', () => window.app?.showPage('refer'));
    }

    // 🔥 CSRF Token getter using AuthHelper
    async getCsrf() {
        if (window.AuthHelper && typeof window.AuthHelper.getCsrfToken === 'function') {
            return await window.AuthHelper.getCsrfToken();
        }
        // Fallback
        return document.querySelector('meta[name="csrf-token"]')?.content || 
               document.querySelector('input[name="csrf_token"]')?.value || '';
    }

    async handleInviteLink() {
        if (!this.roomCode) return;
        const result = await this.checkRoom(this.roomCode);
        if (result.success) {
            const room = result.data.room;
            if (room.is_full) { 
                this.showMessage('❌ Room is full', 'error'); 
                return; 
            }
            this.showMessage(`✅ Room available! Entry: ₹${room.entry_fee}`, 'success');
        } else {
            this.showMessage('❌ Room not found', 'error');
        }
    }

    async checkRoom(roomCode) {
        try {
            const r = await fetch(`${this.apiBase}?action=check_room&room=${encodeURIComponent(roomCode)}`, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            return await r.json();
        } catch (e) { 
            return { success: false, message: 'Network error' }; 
        }
    }

    async joinRoom(roomCode) {
        if (!this.userId) { 
            window.app?.openAuthModal('login'); 
            return; 
        }
        
        // 🔥 AuthHelper se fresh CSRF token lo
        const csrf = await this.getCsrf();
        
        try {
            const r = await fetch(`${this.apiBase}?action=join`, {
                method: 'POST',
                credentials: 'include',
                headers: { 
                    'Content-Type': 'application/json', 
                    'X-CSRF-Token': csrf 
                },
                body: JSON.stringify({ room_code: roomCode, csrf_token: csrf })
            });
            const d = await r.json();
            
            // 🔥 Naya token update karo
            if (d.data && d.data.csrf_token && window.AuthHelper) {
                window.AuthHelper.updateCsrfToken(d.data.csrf_token);
            }
            
            if (d.success && d.data && d.data.redirect_url) {
                this.showMessage('✅ ' + (d.message || 'Joined!'), 'success');
                setTimeout(() => window.location.href = d.data.redirect_url, 1500);
            } else {
                this.showMessage(d.success ? '✅ ' + d.message : '❌ ' + (d.message || 'Failed'), d.success ? 'success' : 'error');
            }
        } catch (e) { 
            this.showMessage('❌ Network error', 'error'); 
        }
    }

    shareInvite() {
        const code = document.getElementById('referCodeText')?.textContent || '';
        const text = `🎲 Join Zupeex! Use code: ${code}`;
        if (navigator.share) { 
            navigator.share({ title: 'Zupeex', text, url: window.location.href }).catch(() => {}); 
        } else { 
            this.copyText(text); 
        }
    }

    copyInviteCode() {
        const code = document.getElementById('referCodeText')?.textContent || '';
        this.copyText(code);
    }

    copyText(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(() => this.showMessage('✅ Copied!', 'success'));
        } else {
            const i = document.createElement('input');
            i.value = text;
            document.body.appendChild(i);
            i.select();
            document.execCommand('copy');
            document.body.removeChild(i);
            this.showMessage('✅ Copied!', 'success');
        }
    }

    showMessage(msg, type = 'info') {
        const colors = { success: '#047857', error: '#B91C1C', warning: '#B45309', info: '#7D02AB' };
        const el = document.getElementById('message') || document.createElement('div');
        if (!el.id) { 
            el.id = 'message'; 
            document.querySelector('.join-card')?.appendChild(el); 
        }
        el.style.cssText = `color:${colors[type]};background:${colors[type]}15;padding:12px;border-radius:8px;margin-bottom:16px;font-size:14px;font-weight:700`;
        el.textContent = msg;
    }
}

document.addEventListener('DOMContentLoaded', () => { 
    window.inviteSystem = new InviteSystem(); 
});
console.log('📨 Invite System v2.3 loaded with CSRF integration');