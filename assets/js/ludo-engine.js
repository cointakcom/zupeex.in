/**
 * ======================================================
 * LUDO-ENGINE.JS - DOM-Based Ludo Board (4-Player)
 * Zupeex - Server-Authoritative 1v1 + 1v4 Engine
 * Version: 7.0.0 - FULL FINAL (All Fixes Applied)
 * ------------------------------------------------------
 * CRITICAL FIXES IN THIS VERSION:
 *   1. Slot vs Colour separation — server ka player_colors mapping
 *      hamesha use hota hai, slot number kabhi colour ki tarah nahi.
 *   2. _buildBoardDOM() ab _colorFor() ke confirmed colour se token
 *      ko sahi yard mein rakhta hai (constructor ke waqt bhi).
 *   3. Naya clean SVG board — black grid lines, 1.5px width, saare
 *      15x15 cells saaf dikhte hain (Blue/Green path sameth).
 *   4. Token CSS ab aspect-ratio: 1/1 ke saath perfect circle hai.
 *   5. _applyServerMatch() mein this.players server se update hota hai
 *      taaki colour mapping hamesha latest rahe.
 *   6. activePlayerNumbers mein NaN filter + sort.
 *   7. Base64 SVG hataya — ab readable SVG string hai (easy to edit).
 *
 * IMPORTANT: Yeh file sirf RENDER karti hai. Dice value aur move
 * legality hamesha api/game.php se aati hai — server authoritative
 * rehta hai.
 * ======================================================
 */

// ======================================================
// BOARD GEOMETRY CONSTANTS (RENDERING ONLY)
// ======================================================

// 52-cell outer loop — server ke computeMove() ke global ring ke
// saath 1:1 align. Yeh LUDO_TRACK_CELLS[row, col] 15x15 grid par
// map karta hai. Server ka absolute position (0-51) ko physical
// board cell par le jaata hai.
const LUDO_TRACK_CELLS = [
    [6,13],[6,12],[6,11],[6,10],[6,9],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8],[0,7],[0,6],
    [1,6],[2,6],[3,6],[4,6],[5,6],[6,5],[6,4],[6,3],[6,2],[6,1],[6,0],[7,0],[8,0],
    [8,1],[8,2],[8,3],[8,4],[8,5],[9,6],[10,6],[11,6],[12,6],[13,6],[14,6],[14,7],[14,8],
    [13,8],[12,8],[11,8],[10,8],[9,8],[8,9],[8,10],[8,11],[8,12],[8,13],[8,14],[7,14],[6,14]
];

// Har colour ka private home lane (6 cells), server ke LUDO_STARTS se
// derived. Colour 1=Green start 0 → Top-Right lane; 2=Red start 13 →
// Top-Left lane; 3=Blue start 26 → Bottom-Left lane; 4=Yellow start 39
// → Bottom-Right lane.
const LUDO_LANE_CELLS = {
    1: [[7,13],[7,12],[7,11],[7,10],[7,9],[7,8]],   // Green  → Top-Right lane
    2: [[1,7],[2,7],[3,7],[4,7],[5,7],[6,7]],       // Red    → Top-Left lane
    3: [[7,1],[7,2],[7,3],[7,4],[7,5],[7,6]],       // Blue   → Bottom-Left lane
    4: [[13,7],[12,7],[11,7],[10,7],[9,7],[8,7]]    // Yellow → Bottom-Right lane
};

// Har colour ka yard (base) — 4 slots, [row, col] format mein.
//   Colour 1 (Green)  → Top-Right yard  (row 1.5-3.5, col 10.5-12.5)
//   Colour 2 (Red)    → Top-Left yard   (row 1.5-3.5, col 1.5-3.5)
//   Colour 3 (Blue)   → Bottom-Left yard(row 10.5-12.5, col 1.5-3.5)
//   Colour 4 (Yellow) → Bottom-Right yard(row 10.5-12.5, col 10.5-12.5)
const LUDO_YARD_CELLS = {
    1: [[1.5,10.58],[3.57,10.58],[1.5,12.43],[3.57,12.43]],      // Green  Top-Right
    2: [[3.42,1.5],[3.42,3.57],[1.57,1.5],[1.57,3.57]],          // Red    Top-Left
    3: [[10.5,1.58],[12.54,1.58],[10.5,3.45],[12.54,3.45]],      // Blue   Bottom-Left
    4: [[12.42,10.5],[12.42,12.54],[10.55,10.5],[10.55,12.54]]   // Yellow Bottom-Right
};

const LUDO_SAFE_INDICES = [0, 8, 13, 21, 26, 34, 39, 47];

// Server ke LUDO_STARTS se 1:1 match — kabhi change nahi karna.
const LUDO_STARTS = { 1: 0, 2: 13, 3: 26, 4: 39 };
const LUDO_HOMERUN_START = 52;
const LUDO_FINISHED = 58;
const GRID_SIZE = 15;
const STEP_PCT = 100 / GRID_SIZE;

// Colour palette — server ke LUDO_STARTS se aligned:
//   1 = Green (Top-Right), 2 = Red (Top-Left),
//   3 = Blue (Bottom-Left), 4 = Yellow (Bottom-Right)
const LUDO_COLORS = {
    1: { name: 'Green',  main: '#0B6E2C' },
    2: { name: 'Red',    main: '#E63329' },
    3: { name: 'Blue',   main: '#1E9FE0' },
    4: { name: 'Yellow', main: '#F5C518' }
};

// ======================================================
// PURE GEOMETRY HELPERS (RENDERING ONLY)
// ======================================================
function cellToPercent([row, col]) {
    return { top: row * STEP_PCT, left: col * STEP_PCT };
}

function cellCenterPercent([row, col]) {
    return { top: (row + 0.5) * STEP_PCT, left: (col + 0.5) * STEP_PCT };
}

function pixelForPosition(colorNumber, pos) {
    if (pos === -1 || pos === undefined || pos === null) return null;

    // Shared main ring (0-51) — pos hi absolute hai, colour se koi
    // lena-dena nahi. Sabhi players isi ring ko share karte hain.
    if (pos >= 0 && pos < 52) {
        const cell = LUDO_TRACK_CELLS[pos];
        return cell ? cellCenterPercent(cell) : null;
    }

    // Private home lane (52-57) — yahan colourNumber zaroori hai.
    if (pos >= LUDO_HOMERUN_START && pos < LUDO_FINISHED - 1) {
        const idx = pos - LUDO_HOMERUN_START;
        const cell = (LUDO_LANE_CELLS[colorNumber] || [])[idx];
        return cell ? cellCenterPercent(cell) : null;
    }

    // Finished (58) — final home cell par render karo
    if (pos === LUDO_FINISHED - 1 || pos === LUDO_FINISHED) {
        const cell = (LUDO_LANE_CELLS[colorNumber] || [])[5];
        return cell ? cellCenterPercent(cell) : null;
    }
    return null;
}

function yardPixel(colorNumber, tokenIndex) {
    const cell = (LUDO_YARD_CELLS[colorNumber] || [])[tokenIndex];
    return cell ? cellCenterPercent(cell) : { top: 45, left: 45 };
}

// Visual-only next-step helper — server ke computeMove() ke saath
// align rehna zaroori hai.
function nextVisualStep(colorNumber, pos) {
    const start = LUDO_STARTS[colorNumber];
    if (pos >= 0 && pos < 52) {
        const traveled = ((pos - start) % 52 + 52) % 52;
        const newTraveled = traveled + 1;
        if (newTraveled < 52) return (start + newTraveled) % 52;
        return newTraveled;
    }
    if (pos >= LUDO_HOMERUN_START && pos < LUDO_FINISHED) return pos + 1;
    return pos;
}

function buildHopPath(colorNumber, fromPos, toPos) {
    if (fromPos === -1 || fromPos === toPos || fromPos === undefined || fromPos === null) return [];
    const path = [];
    let cur = fromPos;
    let guard = 0;
    while (cur !== toPos && guard < 60) {
        cur = nextVisualStep(colorNumber, cur);
        path.push(cur);
        guard++;
    }
    if (cur !== toPos) return [];
    return path;
}

// ======================================================
// CLEAN SVG BOARD (readable, editable, no base64)
// ======================================================
const LUDO_BOARD_SVG = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600" font-family="Arial, sans-serif">
  <!-- Background -->
  <rect x="0" y="0" width="600" height="600" fill="#FFFFFF"/>

  <!-- ==================== TOP-LEFT YARD: RED ==================== -->
  <rect x="0" y="0" width="240" height="240" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="40" y="40" width="160" height="160" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="80" cy="80" r="24" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="160" cy="80" r="24" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="80" cy="160" r="24" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="160" cy="160" r="24" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== TOP-RIGHT YARD: GREEN ==================== -->
  <rect x="360" y="0" width="240" height="240" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="400" y="40" width="160" height="160" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="440" cy="80" r="24" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="520" cy="80" r="24" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="440" cy="160" r="24" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="520" cy="160" r="24" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== BOTTOM-LEFT YARD: BLUE ==================== -->
  <rect x="0" y="360" width="240" height="240" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="40" y="400" width="160" height="160" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="80" cy="440" r="24" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="160" cy="440" r="24" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="80" cy="520" r="24" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="160" cy="520" r="24" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== BOTTOM-RIGHT YARD: YELLOW ==================== -->
  <rect x="360" y="360" width="240" height="240" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="400" y="400" width="160" height="160" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="440" cy="440" r="24" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="520" cy="440" r="24" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="440" cy="520" r="24" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <circle cx="520" cy="520" r="24" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== CENTER HOME ==================== -->
  <polygon points="240,240 360,240 300,300" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <polygon points="360,240 360,360 300,300" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <polygon points="240,360 360,360 300,300" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <polygon points="240,240 240,360 300,300" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="240" width="120" height="120" fill="none" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== GREEN PATH (Top vertical) ==================== -->
  <rect x="280" y="40" width="40" height="40" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="40" width="40" height="40" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="80" width="40" height="40" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="120" width="40" height="40" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="160" width="40" height="40" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="200" width="40" height="40" fill="#0B6E2C" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== RED PATH (Left horizontal) ==================== -->
  <rect x="80" y="240" width="40" height="40" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="120" y="240" width="40" height="40" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="40" y="280" width="40" height="40" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="80" y="280" width="40" height="40" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="120" y="280" width="40" height="40" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="160" y="280" width="40" height="40" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="200" y="280" width="40" height="40" fill="#E63329" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== BLUE PATH (Bottom vertical) ==================== -->
  <rect x="280" y="360" width="40" height="40" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="400" width="40" height="40" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="440" width="40" height="40" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="480" width="40" height="40" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="520" width="40" height="40" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="520" width="40" height="40" fill="#1E9FE0" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== YELLOW PATH (Right horizontal) ==================== -->
  <rect x="440" y="240" width="40" height="40" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="480" y="240" width="40" height="40" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="360" y="280" width="40" height="40" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="400" y="280" width="40" height="40" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="440" y="280" width="40" height="40" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="480" y="280" width="40" height="40" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="520" y="280" width="40" height="40" fill="#F5C518" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== WHITE GRID CELLS ==================== -->
  <!-- Top vertical arm -->
  <rect x="240" y="0" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="0" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="40" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="80" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="80" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="120" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="120" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="160" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="160" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="200" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="200" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- Left horizontal arm -->
  <rect x="0" y="240" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="0" y="280" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="0" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="40" y="240" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="160" y="240" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="200" y="240" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="40" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="80" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="120" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="160" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="200" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- Right horizontal arm -->
  <rect x="360" y="240" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="400" y="240" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="520" y="240" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="560" y="240" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="360" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="400" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="440" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="480" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="520" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="560" y="320" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- Bottom vertical arm -->
  <rect x="240" y="360" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="360" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="400" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="400" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="440" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="440" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="480" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="480" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="520" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="240" y="560" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="280" y="560" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>
  <rect x="320" y="560" width="40" height="40" fill="#FFFFFF" stroke="#1a1a1a" stroke-width="1.5"/>

  <!-- ==================== SYMBOLS (Stars & Arrows) ==================== -->
  <polygon points="260,50 263,56 270,56 265,61 267,68 260,64 253,68 255,61 250,56 257,56" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linejoin="round"/>
  <polygon points="100,330 103,336 110,336 105,341 107,348 100,344 93,348 95,341 90,336 97,336" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linejoin="round"/>
  <polygon points="340,490 343,496 350,496 345,501 347,508 340,504 333,508 335,501 330,496 337,496" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linejoin="round"/>
  <polygon points="500,250 503,256 510,256 505,261 507,268 500,264 493,268 495,261 490,256 497,256" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linejoin="round"/>

  <polygon points="290,10 300,30 310,10" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linejoin="round"/>
  <polygon points="290,590 300,570 310,590" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linejoin="round"/>
  <polygon points="10,290 30,300 10,310" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linejoin="round"/>
  <polygon points="590,290 570,300 590,310" fill="none" stroke="#1a1a1a" stroke-width="1.5" stroke-linejoin="round"/>

  <!-- Outer border -->
  <rect x="1" y="1" width="598" height="598" fill="none" stroke="#1a1a1a" stroke-width="2"/>
</svg>`;

// SVG ko data URI mein convert karo (base64 ke bina, safer + readable)
const LUDO_BOARD_IMAGE_DATA_URI = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(LUDO_BOARD_SVG);

// ======================================================
// CSS INJECTION
// ======================================================
let stylesInjected = false;

function injectStyles(boardImageUrl) {
    if (stylesInjected || document.getElementById('ludo-engine-styles')) {
        stylesInjected = true;
        return;
    }
    const css = `
    .ludo-board-root {
        position: relative;
        width: 100%;
        height: 100%;
        aspect-ratio: 1 / 1;
        max-width: 520px;
        max-height: 520px;
        background-image: url('${boardImageUrl}') !important;
        background-size: 100% 100% !important;
        background-repeat: no-repeat !important;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(125,2,171,0.15);
        overflow: hidden;
        touch-action: none;
    }
    .player-piece {
        position: absolute;
        width: 5.5%;
        height: 5.5%;
        aspect-ratio: 1 / 1;
        box-sizing: border-box;
        border: 2px solid rgba(0,0,0,0.55);
        border-radius: 50%;
        transform: translate(-50%, -50%);
        transition: top .22s ease, left .22s ease;
        box-shadow: 0 1px 2px rgba(0,0,0,0.5);
        z-index: 10;
        cursor: default;
    }
    .player-piece.highlight {
        cursor: pointer;
        border: 2px dashed #FFFFFF;
        animation: ludo-spin 1s infinite linear;
        z-index: 11;
    }
    .player-piece.pop-in {
        animation: ludo-pop .25s ease;
    }
    @keyframes ludo-spin {
        0%   { transform: translate(-50%, -50%) rotate(0deg); }
        50%  { transform: translate(-50%, -50%) rotate(180deg) scale(1.4); }
        100% { transform: translate(-50%, -50%) rotate(360deg); }
    }
    @keyframes ludo-pop {
        0%   { transform: translate(-50%, -50%) scale(0.4); opacity: 0.4; }
        100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
    }
    `;
    const styleEl = document.createElement('style');
    styleEl.id = 'ludo-engine-styles';
    styleEl.textContent = css;
    document.head.appendChild(styleEl);
    stylesInjected = true;
}

// ======================================================
// LUDO ENGINE CLASS
// ======================================================
class LudoEngine {
    constructor(container, options = {}) {
        this.container = container;
        this.matchId = options.matchId;
        this.roomCode = options.roomCode || '';
        this.myPlayerNumber = options.myPlayerNumber || 1;
        this.gameMode = options.gameMode || '1vs1';
        this.basePath = options.basePath || '';
        this.realtimeUrl = options.realtimeUrl || '';
        this.socket = null;
        this.csrfToken = options.csrfToken || '';
        this.players = options.players || {};
        this.activePlayerNumbers = Object.keys(this.players)
            .map(k => parseInt(k.replace('player', ''), 10))
            .filter(n => !isNaN(n))
            .sort((a, b) => a - b);

        this.state = {
            status: 'waiting',
            currentTurn: 1,
            diceValue: 0,
            hasRolled: false,
            canRoll: false,
            isGameOver: false,
            winnerId: null,
            board: this._emptyBoard(),
        };

        this.selectableTokens = [];
        this.isSyncing = false;
        this.pollTimer = null;
        this._tokenEls = {};
        this._animating = {};

        this.callbacks = {
            onTurnChange: null,
            onDiceRoll: null,
            onTokenMove: null,
            onCapture: null,
            onWin: null,
            onGameStateUpdate: null,
            onError: null,
            onForfeit: null,
        };

        injectStyles(LUDO_BOARD_IMAGE_DATA_URI);
        this._buildBoardDOM();
        this._bindBoardClicks();
        this._bindExternalDiceClick();
    }

    _emptyBoard() {
        const b = {};
        for (let p = 1; p <= 4; p++) {
            b['player' + p] = { token1: -1, token2: -1, token3: -1, token4: -1 };
        }
        return b;
    }

    on(event, callback) {
        if (this.callbacks.hasOwnProperty(event)) this.callbacks[event] = callback;
    }

    async _getCsrf() {
        if (window.AuthHelper && typeof window.AuthHelper.getCsrfToken === 'function') {
            return await window.AuthHelper.getCsrfToken();
        }
        return this.csrfToken || '';
    }

    _updateCsrf(newToken) {
        if (newToken && window.AuthHelper && typeof window.AuthHelper.updateCsrfToken === 'function') {
            window.AuthHelper.updateCsrfToken(newToken);
        }
        this.csrfToken = newToken || this.csrfToken;
    }

    // ==============================================
    // YARD → COLOUR MAPPING (CRITICAL FIX)
    //
    // Server ka player_colors = Slot → Colour mapping.
    // Slot 1 = colour 1 (Green), Slot 2 = colour 3 (Blue) in 1vs1.
    // Yeh method hamesha colour number return karta hai, slot
    // number NAHI. Fallback slot number hai lekin warning log
    // karta hai agar data missing ho.
    // ==============================================
    _colorFor(playerNumber) {
        const info = this.players['player' + playerNumber];
        if (info && info.color !== undefined && info.color !== null) {
            const c = parseInt(info.color, 10);
            if (c >= 1 && c <= 4) return c;
        }
        console.warn(
            '[LudoEngine] Missing colour for slot ' + playerNumber +
            ' — falling back to slot number. Check PLAYERS_MAP color field.'
        );
        return playerNumber;
    }

    // ==============================================
    // BOARD DOM CONSTRUCTION
    // ==============================================
    _buildBoardDOM() {
        this.container.classList.add('ludo-board-root');
        this.container.innerHTML = '';

        this._tokenEls = {};

        for (const p of this.activePlayerNumbers) {
            this._tokenEls[p] = [];

            const colorNum = this._colorFor(p);
            const color = LUDO_COLORS[colorNum];
            if (!color) {
                console.error('[LudoEngine] No palette for colour ' + colorNum + ' (slot ' + p + ')');
                continue;
            }

            for (let t = 0; t < 4; t++) {
                const el = document.createElement('div');
                el.className = 'player-piece';
                el.setAttribute('data-player', String(p));
                el.setAttribute('data-piece', String(t + 1));
                el.style.background = color.main;

                const yp = yardPixel(colorNum, t);
                el.style.top = yp.top + '%';
                el.style.left = yp.left + '%';

                this.container.appendChild(el);
                this._tokenEls[p].push(el);
            }
        }
    }

    _bindBoardClicks() {
        this.container.addEventListener('click', (e) => {
            const el = e.target.closest('.player-piece');
            if (!el || !el.classList.contains('highlight')) return;
            const tokenNumber = parseInt(el.getAttribute('data-piece'), 10);
            this.moveToken(tokenNumber);
        });
    }

    _bindExternalDiceClick() {
        const diceEl = document.getElementById('diceDisplay');
        if (diceEl) {
            diceEl.style.cursor = 'pointer';
            diceEl.style.pointerEvents = 'auto';
            diceEl.addEventListener('click', () => {
                if (this.state.canRoll) this.rollDice();
            });
        }
    }

    // ==============================================
    // SERVER SYNC
    // ==============================================
    async init() {
        await this.syncState();
        this._initRealtime();
        this._startPolling();
    }

    _initRealtime() {
        if (typeof io === 'undefined') {
            console.warn('[LudoEngine] Socket.io client not found — polling only.');
            return;
        }
        try {
            this.socket = io(this.realtimeUrl || undefined, {
                transports: ['websocket', 'polling'],
                reconnection: true,
                reconnectionDelay: 2000,
                reconnectionAttempts: 2,
                timeout: 5000,
            });
            this.socket.on('connect', () => {
                this.socket.emit('join_match', this.roomCode);
            });
            this.socket.on('state_changed', () => {
                this.syncState();
            });
            this.socket.io.on('reconnect_failed', () => {
                this._stopRealtime();
            });
        } catch (e) {
            console.warn('[LudoEngine] realtime init failed', e);
        }
    }

    _stopRealtime() {
        if (this.socket) {
            try {
                this.socket.emit('leave_match', this.roomCode);
                this.socket.disconnect();
            } catch (e) { /* ignore */ }
            this.socket = null;
        }
    }

    _startPolling() {
        if (this.pollTimer) clearInterval(this.pollTimer);
        this.pollTimer = setInterval(() => {
            if (!this.state.isGameOver) this.syncState();
        }, 8000);
    }

    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        this._stopRealtime();
    }

    async syncState() {
        if (this.isSyncing) return;
        this.isSyncing = true;
        try {
            const res = await fetch(this.basePath + '/api/game.php?action=get_state&match_id=' + this.matchId, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (data.data && data.data.csrf_token) {
                this._updateCsrf(data.data.csrf_token);
            }

            if (data.success) {
                this._applyServerMatch(data.data);
            } else if (this.callbacks.onError) {
                this.callbacks.onError(data.message || 'Sync failed');
            }
        } catch (e) {
            console.warn('[LudoEngine] sync error', e);
        } finally {
            this.isSyncing = false;
        }
    }

    _applyServerMatch(data) {
        const m = data.match;
        const prevTurn = this.state.currentTurn;
        const prevDice = this.state.diceValue;
        const prevBoard = this.state.board;

        // Server se latest players (colour mapping confirm) update karo.
        if (data.players && Object.keys(data.players).length) {
            this.players = data.players;
        }

        this.state.status = m.status;
        this.state.currentTurn = m.current_turn;
        this.state.diceValue = m.dice_value;
        this.state.hasRolled = m.has_rolled;
        this.state.canRoll = m.is_my_turn && !m.has_rolled && ['playing', 'ready'].includes(m.status);
        this.state.isMyTurn = m.is_my_turn;
        this.state.consecutiveSixes = m.consecutive_sixes || 0;
        this.state.board = data.board || this._emptyBoard();
        this.state.isGameOver = (m.status === 'completed');
        this.state.winnerId = m.winner_id;
        this.state.winningAmount = m.winning_amount;
        this.state.prizePool = m.prize_pool;
        this.state.secondsRemainingInTurn = m.seconds_remaining_in_turn ?? null;
        this.state.seconds_remaining = m.seconds_remaining ?? null;
        this.state.turnStartedAt = m.turn_started_at ?? null;

        this._updateSelectableTokens();
        this._renderBoardDiff(prevBoard, this.state.board);

        if (this.callbacks.onGameStateUpdate) this.callbacks.onGameStateUpdate(this.state);
        if (prevTurn !== this.state.currentTurn && this.callbacks.onTurnChange) {
            this.callbacks.onTurnChange(this.state.currentTurn);
        }
        if (this.state.diceValue > 0 && this.state.diceValue !== prevDice && this.callbacks.onDiceRoll) {
            this.callbacks.onDiceRoll(this.state.diceValue);
        }
        if (this.state.isGameOver && this.callbacks.onWin) {
            this.callbacks.onWin(this.state.winnerId, this.state.winningAmount);
            this.stopPolling();
        }
    }

    _renderBoardDiff(prevBoard, newBoard) {
        for (const p of this.activePlayerNumbers) {
            const key = 'player' + p;
            const colorNum = this._colorFor(p);
            const prevTokens = (prevBoard && prevBoard[key]) || {};
            const newTokens = newBoard[key] || {};

            for (let t = 1; t <= 4; t++) {
                const el = (this._tokenEls[p] || [])[t - 1];
                if (!el) continue;

                const prevPos = prevTokens['token' + t] ?? -1;
                const newPos = newTokens['token' + t] ?? -1;
                const animKey = p + '-' + t;

                if (prevPos === newPos) continue;

                if (this._animating[animKey]) {
                    this._placeTokenInstant(el, colorNum, t - 1, newPos);
                    continue;
                }

                if (prevPos === -1) {
                    this._placeTokenInstant(el, colorNum, t - 1, newPos);
                    el.classList.remove('pop-in');
                    void el.offsetWidth;
                    el.classList.add('pop-in');
                    continue;
                }

                const path = buildHopPath(colorNum, prevPos, newPos);
                if (!path.length) {
                    this._placeTokenInstant(el, colorNum, t - 1, newPos);
                    continue;
                }
                this._animateHop(el, colorNum, t - 1, path, newPos, animKey);
            }
        }
        this._refreshHighlights();
    }

    _placeTokenInstant(el, colorNum, tokenIndex, pos) {
        const px = (pos === -1) ? yardPixel(colorNum, tokenIndex) : pixelForPosition(colorNum, pos);
        if (!px) return;
        el.style.top = px.top + '%';
        el.style.left = px.left + '%';
    }

    _animateHop(el, colorNum, tokenIndex, path, finalPos, animKey) {
        this._animating[animKey] = true;
        let i = 0;
        const stepMs = 160;
        const step = () => {
            const pos = path[i];
            const px = pixelForPosition(colorNum, pos);
            if (px) {
                el.style.top = px.top + '%';
                el.style.left = px.left + '%';
            }
            i++;
            if (i < path.length) {
                setTimeout(step, stepMs);
            } else {
                this._placeTokenInstant(el, colorNum, tokenIndex, finalPos);
                this._animating[animKey] = false;
            }
        };
        step();
    }

    _refreshHighlights() {
        Object.values(this._tokenEls).forEach(list => list.forEach(el => el.classList.remove('highlight')));
        if (!this.state.isMyTurn) return;
        const mine = this._tokenEls[this.myPlayerNumber] || [];
        this.selectableTokens.forEach(t => {
            const el = mine[t - 1];
            if (el) el.classList.add('highlight');
        });
    }

    _updateSelectableTokens() {
        this.selectableTokens = [];
        if (!this.state.isMyTurn || !this.state.hasRolled || this.state.diceValue <= 0) return;
        const playerKey = 'player' + this.myPlayerNumber;
        const tokens = this.state.board[playerKey] || {};
        const myColor = this._colorFor(this.myPlayerNumber);
        for (let t = 1; t <= 4; t++) {
            const pos = tokens['token' + t] ?? -1;
            if (this._canMove(pos, this.state.diceValue, myColor)) {
                this.selectableTokens.push(t);
            }
        }
    }

    _canMove(pos, dice, colorNumber) {
        if (pos === -1) return dice === 6;
        if (pos === LUDO_FINISHED) return false;
        if (pos >= 0 && pos < 52) return true;
        if (pos >= LUDO_HOMERUN_START && pos < LUDO_FINISHED) return (pos + dice) <= LUDO_FINISHED;
        return false;
    }

    // ==============================================
    // SERVER ACTIONS
    // ==============================================
    async rollDice() {
        if (!this.state.canRoll) return;
        const csrf = await this._getCsrf();
        try {
            const res = await fetch(this.basePath + '/api/game.php?action=roll', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    match_id: this.matchId,
                    csrf_token: csrf
                })
            });
            const data = await res.json();

            if (data.data && data.data.csrf_token) {
                this._updateCsrf(data.data.csrf_token);
            }

            if (!data.success && data.data && data.data.refresh_needed) {
                this._updateCsrf(data.data.csrf_token);
                return await this.rollDice();
            }

            if (data.success) {
                if (typeof data.data.dice_value === 'number' && this.callbacks.onDiceRoll) {
                    this.callbacks.onDiceRoll(data.data.dice_value);
                    this.state.diceValue = data.data.dice_value;
                }
                if (data.data.forfeited && this.callbacks.onForfeit) {
                    this.callbacks.onForfeit();
                }
                this.syncState();
            } else if (this.callbacks.onError) {
                this.callbacks.onError(data.message || 'Roll failed');
            }
            return data;
        } catch (e) {
            console.error('[LudoEngine] rollDice error', e);
            if (this.callbacks.onError) this.callbacks.onError('Network error');
        }
    }

    async moveToken(tokenNumber) {
        if (!this.selectableTokens.includes(tokenNumber)) return;
        const csrf = await this._getCsrf();
        try {
            const res = await fetch(this.basePath + '/api/game.php?action=move', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    match_id: this.matchId,
                    token_number: tokenNumber,
                    csrf_token: csrf
                })
            });
            const data = await res.json();

            if (data.data && data.data.csrf_token) {
                this._updateCsrf(data.data.csrf_token);
            }

            if (!data.success && data.data && data.data.refresh_needed) {
                this._updateCsrf(data.data.csrf_token);
                return await this.moveToken(tokenNumber);
            }

            if (data.success) {
                if (data.data.captured && this.callbacks.onCapture) {
                    this.callbacks.onCapture();
                }
                if (this.callbacks.onTokenMove) {
                    this.callbacks.onTokenMove(this.myPlayerNumber, tokenNumber);
                }
                this.syncState();
            } else if (this.callbacks.onError) {
                this.callbacks.onError(data.message || 'Move failed');
            }
            return data;
        } catch (e) {
            console.error('[LudoEngine] moveToken error', e);
            if (this.callbacks.onError) this.callbacks.onError('Network error');
        }
    }

    getState() {
        return this.state;
    }

    getPlayers() {
        return this.players;
    }

    destroy() {
        this.stopPolling();
    }
}

// ======================================================
// EXPORTS
// ======================================================
window.LudoEngine = LudoEngine;
window.LUDO_COLORS = LUDO_COLORS;
console.log('🎲 Ludo Engine v7.0.0 loaded — full fix applied (yard alignment + clean grid + slot/colour separation)');