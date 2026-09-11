/**
 * ======================================================
 * SERVER.JS - Real-time relay for Zupeex
 * Version: 4.0.0 - PURE RELAY, NO LOCAL GAME LOGIC
 * ======================================================
 */

const express = require('express');
const http = require('http');
const socketIO = require('socket.io');
const cors = require('cors');
const crypto = require('crypto');

const PORT = process.env.PORT || 3000;
const INTERNAL_NOTIFY_SECRET = process.env.INTERNAL_NOTIFY_SECRET || '';
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || '*').split(',').map(s => s.trim());

if (!INTERNAL_NOTIFY_SECRET) {
    console.warn('⚠️  INTERNAL_NOTIFY_SECRET is not set — /internal/notify will reject ALL requests.');
}

const app = express();
const server = http.createServer(app);
const io = socketIO(server, {
    cors: { origin: ALLOWED_ORIGINS, methods: ['GET', 'POST'], credentials: true },
    pingTimeout: 60000,
    pingInterval: 25000
});

app.use(cors({ origin: ALLOWED_ORIGINS }));
app.use(express.json());

// roomCode -> Set of socket.id currently joined to that room
const roomSockets = new Map();

function timingSafeEqual(a, b) {
    const bufA = Buffer.from(String(a));
    const bufB = Buffer.from(String(b));
    if (bufA.length !== bufB.length) return false;
    try { return crypto.timingSafeEqual(bufA, bufB); } catch (e) { return false; }
}

io.on('connection', (socket) => {
    let joinedRoom = null;

    socket.on('join_room', (data) => {
        const roomCode = (data && data.roomCode) ? String(data.roomCode).trim() : '';
        if (!roomCode) return;

        if (joinedRoom && joinedRoom !== roomCode) {
            leaveRoom(socket, joinedRoom);
        }

        socket.join(roomCode);
        joinedRoom = roomCode;
        if (!roomSockets.has(roomCode)) roomSockets.set(roomCode, new Set());
        roomSockets.get(roomCode).add(socket.id);
    });

    socket.on('leave_room', () => {
        if (joinedRoom) leaveRoom(socket, joinedRoom);
        joinedRoom = null;
    });

    socket.on('disconnect', () => {
        if (joinedRoom) leaveRoom(socket, joinedRoom);
    });
});

function leaveRoom(socket, roomCode) {
    socket.leave(roomCode);
    const set = roomSockets.get(roomCode);
    if (set) {
        set.delete(socket.id);
        if (set.size === 0) roomSockets.delete(roomCode);
    }
}

// Internal endpoint — PHP calls this after every successful game action
app.post('/internal/notify', (req, res) => {
    const providedSecret = req.headers['x-internal-secret'] || '';
    if (!INTERNAL_NOTIFY_SECRET || !timingSafeEqual(providedSecret, INTERNAL_NOTIFY_SECRET)) {
        return res.status(403).json({ success: false, message: 'Forbidden' });
    }

    const roomCode = req.body && req.body.room_code ? String(req.body.room_code).trim() : '';
    if (!roomCode) {
        return res.status(400).json({ success: false, message: 'room_code required' });
    }

    io.to(roomCode).emit('state_changed', { room_code: roomCode, at: Date.now() });

    res.json({ success: true, notified_sockets: (roomSockets.get(roomCode) || new Set()).size });
});

// Health check
app.get('/health', (req, res) => {
    res.json({
        success: true,
        uptime_seconds: Math.floor(process.uptime()),
        active_rooms: roomSockets.size,
        active_sockets: io.engine.clientsCount,
    });
});

server.listen(PORT, () => {
    console.log(`🔌 Zupeex real-time relay listening on port ${PORT}`);
    console.log(`   Health check: http://localhost:${PORT}/health`);
    if (!INTERNAL_NOTIFY_SECRET) {
        console.log('   ⚠️  Set INTERNAL_NOTIFY_SECRET to enable push notifications from PHP.');
    }
});

process.on('SIGINT', () => {
    console.log('🛑 Shutting down relay...');
    io.close(() => {
        server.close(() => process.exit(0));
    });
});