/**
 * ======================================================
 * WEBSOCKET-CLIENT.JS - WebSocket Client (PURE RELAY CLIENT)
 * Zupeex - Real-time Relay Client
 * Version: 4.0.0 - ROOT DEPLOYMENT FIX
 *
 * NOTE: This is a PURE RELAY client. It does NOT simulate game logic.
 * It only joins a room and listens for 'state_changed' signals from
 * the Node.js relay server. The actual game state is ALWAYS fetched
 * from the PHP API (server-authoritative).
 * ======================================================
 */

class LudoRealtimeClient {
    constructor(options = {}) {
        // ROOT FIX: WebSocket URL properly resolved
        this.url = options.url || this.getWebSocketUrl();
        this.socket = null;
        this.connected = false;
        this.roomCode = null;
        this.onStateChanged = options.onStateChanged || function () {};
        this.onConnect = options.onConnect || function () {};
        this.onDisconnect = options.onDisconnect || function () {};
    }

    getWebSocketUrl() {
        // Default to same host with port 3000 (Node.js relay server)
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const host = window.location.hostname || 'localhost';
        const port = window.ZUPEEX_WS_PORT || '3000';
        // If on same origin, use current host; otherwise localhost for dev
        if (host === 'localhost' || host === '127.0.0.1') {
            return `${protocol}//${host}:${port}`;
        }
        // Production: use same host with the WebSocket port
        return `${protocol}//${host}:${port}`;
    }

    connect() {
        return new Promise((resolve, reject) => {
            if (typeof io === 'undefined') {
                console.warn('[RealtimeClient] socket.io client not loaded — polling only');
                reject(new Error('socket.io client library not loaded'));
                return;
            }

            let settled = false;
            const safeResolve = () => { if (!settled) { settled = true; resolve(); } };
            const safeReject = (err) => { if (!settled) { settled = true; reject(err); } };

            try {
                this.socket = io(this.url, {
                    transports: ['websocket', 'polling'],
                    reconnection: true,
                    reconnectionAttempts: 5,
                    reconnectionDelay: 1500,
                    timeout: 8000,
                });

                this.socket.on('connect', () => {
                    this.connected = true;
                    if (this.roomCode) this.socket.emit('join_room', { roomCode: this.roomCode });
                    this.onConnect();
                    safeResolve();
                });

                this.socket.on('disconnect', (reason) => {
                    this.connected = false;
                    this.onDisconnect(reason);
                });

                this.socket.on('connect_error', (err) => {
                    safeReject(err);
                });

                // PURE RELAY: Only listen for state_changed signal
                this.socket.on('state_changed', () => {
                    this.onStateChanged();
                });
            } catch (err) {
                safeReject(err);
            }
        });
    }

    joinRoom(roomCode) {
        this.roomCode = roomCode;
        if (this.connected && this.socket) {
            this.socket.emit('join_room', { roomCode: roomCode });
        }
    }

    disconnect() {
        if (this.socket) {
            try { this.socket.emit('leave_room'); } catch (e) {}
            this.socket.disconnect();
        }
        this.connected = false;
    }

    isConnected() {
        return this.connected && this.socket && this.socket.connected;
    }
}

window.LudoRealtimeClient = LudoRealtimeClient;
console.log('📡 Realtime relay client v4.0 loaded (pure relay, no local game logic)');