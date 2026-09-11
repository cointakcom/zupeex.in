/**
 * ======================================================
 * ZUPEEX REALTIME SERVER
 * ------------------------------------------------------
 * A small companion process that sits alongside the existing PHP app.
 * It does NOT run any game logic and does NOT touch the database — PHP
 * remains the single source of truth for everything (dice rolls, token
 * moves, wallet, timers). This server's only job is:
 *
 *   1. Let browsers open a WebSocket and "join" a match's room, keyed by
 *      the match's room_code (e.g. "E0F0F4") — the same identifier
 *      already shown in the game UI.
 *   2. Accept a POST /internal/notify from PHP (running on the same
 *      machine, via config/db.php's notifyRoomViaWebSocket()) whenever
 *      that room's state changes, and instantly relay a lightweight
 *      "state_changed" ping to everyone in that room.
 *
 * The browser reacts to that ping by calling the SAME authenticated PHP
 * endpoint (api/game.php?action=get_state) it already polls today — so
 * nothing about auth, security, or the data shape changes. This server
 * never sees session cookies, wallet balances, or board state.
 *
 * If this process is ever down, unreachable, or slow, the game keeps
 * working exactly as before via the existing polling fallback in
 * ludo-engine.js — this is a pure enhancement, never a hard dependency.
 *
 * Matches config/db.php's WS_RELAY_URL / INTERNAL_NOTIFY_SECRET, which
 * were already stubbed out in this project before this server existed.
 * ======================================================
 */

const express = require('express');
const http = require('http');
const { Server } = require('socket.io');

const PORT = process.env.PORT || 3001;
const HOST = process.env.HOST || '127.0.0.1'; // bind to localhost only — Nginx reverse-proxies public traffic in
const INTERNAL_NOTIFY_SECRET = process.env.INTERNAL_NOTIFY_SECRET || '';
const ALLOWED_ORIGIN = process.env.ALLOWED_ORIGIN || '*';

if (!INTERNAL_NOTIFY_SECRET) {
    console.warn('[zupeex-realtime] WARNING: INTERNAL_NOTIFY_SECRET is not set — /internal/notify is unprotected. Set it in .env before going to production.');
}

function roomKey(roomCode) {
    return `room_${String(roomCode).toUpperCase()}`;
}

const app = express();
app.use(express.json());

const server = http.createServer(app);
const io = new Server(server, {
    cors: { origin: ALLOWED_ORIGIN, methods: ['GET', 'POST'] },
    path: '/socket.io/',
});

function roomSize(roomCode) {
    const room = io.sockets.adapter.rooms.get(roomKey(roomCode));
    return room ? room.size : 0;
}

io.on('connection', (socket) => {
    socket.data.roomCode = null;

    socket.on('join_match', (roomCode) => {
        if (!roomCode || typeof roomCode !== 'string') return;
        socket.join(roomKey(roomCode));
        socket.data.roomCode = roomCode;
    });

    socket.on('leave_match', (roomCode) => {
        if (!roomCode || typeof roomCode !== 'string') return;
        socket.leave(roomKey(roomCode));
    });

    socket.on('disconnect', () => {
        // socket.io removes the socket from all rooms automatically
    });
});

// PHP's notifyRoomViaWebSocket() calls this (from 127.0.0.1, same machine)
// after any state-changing action: dice roll, token move, turn timeout
// skip, or match finish.
app.post('/internal/notify', (req, res) => {
    const secretHeader = req.get('X-Internal-Secret') || '';

    if (INTERNAL_NOTIFY_SECRET && secretHeader !== INTERNAL_NOTIFY_SECRET) {
        return res.status(403).json({ ok: false, error: 'invalid secret' });
    }

    const roomCode = (req.body || {}).room_code;
    if (!roomCode || typeof roomCode !== 'string') {
        return res.status(400).json({ ok: false, error: 'invalid room_code' });
    }

    io.to(roomKey(roomCode)).emit('state_changed', {
        room_code: roomCode,
        at: Date.now(),
    });

    res.json({ ok: true, delivered_to: roomSize(roomCode) });
});

app.get('/health', (req, res) => {
    res.json({ ok: true, uptime_seconds: Math.floor(process.uptime()) });
});

server.listen(PORT, HOST, () => {
    console.log(`[zupeex-realtime] listening on http://${HOST}:${PORT} (socket.io path: /socket.io/)`);
});
