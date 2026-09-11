# Zupeex Realtime Server — Deployment Guide (aaPanel)

This is a small companion Node.js process. It does **not** touch your database
and does **not** run any game logic — PHP stays fully in charge of that. All
this does is relay a "hey, this room changed" ping over WebSocket the instant
PHP tells it to, so players see turns/dice/moves update instantly instead of
waiting up to ~8 seconds for the next poll.

**If this process is ever stopped, crashes, or isn't deployed yet — the game
still works exactly as before**, just slightly slower (polling fallback).
Nothing about this is a hard dependency.

---

## 1. Install Node.js in aaPanel (if not already installed)

1. Open aaPanel → **App Store** → search "Node.js Version Manager" (or "PM2
   Manager") → install it.
2. From the Node.js Version Manager, install **Node.js 18 or newer**.

## 2. Upload this folder

Upload the whole `realtime-server/` folder to your server, e.g.:

```
/www/wwwroot/zupeex.in/realtime-server/
```

## 3. Install dependencies

Via aaPanel's terminal (or SSH):

```bash
cd /www/wwwroot/zupeex.in/realtime-server
npm install --production
```

## 4. Configure environment variables

Create a `.env` file in `realtime-server/` (or set these as environment
variables in aaPanel's Node.js app manager):

```
PORT=3001
HOST=127.0.0.1
INTERNAL_NOTIFY_SECRET=<a long random string — must match .env's INTERNAL_NOTIFY_SECRET in the PHP app root>
ALLOWED_ORIGIN=https://zupeex.in
```

Generate a good secret, e.g. run this once and paste the output:

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

**Important:** put that exact same value in your PHP app's root `.env` file
(the one at `/www/wwwroot/zupeex.in/.env`) as `INTERNAL_NOTIFY_SECRET=...`,
and set:

```
WS_RELAY_URL=http://127.0.0.1:3001
```

(Leave `WS_RELAY_PUBLIC_URL` blank — that makes the browser connect
same-origin through the Nginx reverse proxy set up in step 6, which is the
recommended, simplest setup.)

## 5. Start it with aaPanel's Node.js Project Manager

1. aaPanel → **Website** → **Node project** (or the Node.js manager app) →
   **Add Node project**.
2. Project directory: `/www/wwwroot/zupeex.in/realtime-server`
3. Startup file: `server.js`
4. Port: `3001`
5. Save and start it. aaPanel manages it with PM2 under the hood, so it
   auto-restarts if it crashes or the server reboots.

Check it's alive:

```bash
curl http://127.0.0.1:3001/health
# should return: {"ok":true,"uptime_seconds":...}
```

## 6. Reverse-proxy WebSocket traffic through Nginx (same domain, same SSL)

This lets the browser connect to `wss://zupeex.in/socket.io/...` without
needing a separate port or SSL certificate.

In aaPanel: **Website** → your site (zupeex.in) → **Config File**, and add
this **inside the existing `server { ... }` block** that already has your
SSL (`listen 443 ssl`) config, near the other `location` blocks:

```nginx
location /socket.io/ {
    proxy_pass http://127.0.0.1:3001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
}
```

Save, then reload Nginx (aaPanel usually does this automatically, or use
**Website → your site → Restart**).

## 7. Enable it

In your PHP app's root `.env`, make sure both are set:

```
WS_RELAY_URL=http://127.0.0.1:3001
INTERNAL_NOTIFY_SECRET=<the same secret from step 4>
```

That's it — `notifyRoomViaWebSocket()` in `config/db.php` automatically
starts firing the moment both of these are non-empty. No PHP code changes
needed beyond what's already in this delivery.

## 8. Test it

1. Open a match in two different browsers (or one normal + one incognito
   window), logged in as two different players.
2. Roll the dice or move a token in one window.
3. The other window should update **within well under a second**, instead
   of the old up-to-2-second polling delay.
4. Open the browser console (F12) — you should NOT see repeated
   `connect_error` spam. If you do, double-check step 6's Nginx config and
   that the Node process is running (`curl http://127.0.0.1:3001/health`).

## Rollback / disabling

If anything looks wrong, you can instantly disable this without touching any
code: just clear `WS_RELAY_URL=` (empty) in the PHP app's `.env`. The game
falls straight back to polling — nothing else changes.
