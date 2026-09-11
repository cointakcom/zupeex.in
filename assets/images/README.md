# Banner Images

## Required Image Files:
1. `banner-welcome.png` (400x300) - Welcome bonus banner
2. `banner-refer.png` (400x300) - Referral banner
3. `banner-tournament.png` (400x300) - Tournament banner
4. `banner-secure.png` (400x300) - Security banner

## NEW — Splash Screen (Round 5):
1. `loading.png` — full-screen splash shown for 3 seconds on every app/site
   open. Recommend a portrait image (e.g. 1080x1920) so it fills a mobile
   screen cleanly (object-fit: cover is used, so it'll crop to fill rather
   than stretch). If this file isn't uploaded, the splash still shows a
   solid dark-purple screen with the progress bar — nothing breaks.

## NEW — Auth Modal Background (Round 5):
1. `auth-background.png` — full-screen background shown behind the
   login/register modal. Recommend portrait, e.g. 1080x1920. The modal card
   itself is semi-transparent (glass effect) so this image shows through
   clearly. If missing, falls back to the existing solid dark overlay.

## NEW — Bottom Nav Icons (Round 5):
1. `nav-home.png`
2. `nav-wallet.png`
3. `nav-refer.png`
4. `nav-history.png`
5. `nav-profile.png`
Recommend ~64x64px, transparent background, simple/flat icon style (they
render small, at 22x22 on screen). If any of these is missing, that specific
nav item automatically falls back to its existing built-in SVG icon — no
broken-image icons will show.

## PWA Icons:
1. `icon-72x72.png`
2. `icon-96x96.png`
3. `icon-128x128.png`
4. `icon-144x144.png`
5. `icon-152x152.png`
6. `icon-192x192.png`
7. `icon-384x384.png`
8. `icon-512x512.png`
9. `badge-72x72.png`

## Note:
SVG files are provided as fallback. For production, replace with proper PNG/WebP images.
