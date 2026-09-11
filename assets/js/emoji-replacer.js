/**
 * Emoji Replacer v1.3.0
 * Replaces mapped emoji glyphs with <img> icons safely.
 * Version: 1.3.0 - ROOT DEPLOYMENT FIX
 */

(function (global) {
  'use strict';

  const DEFAULT_OPTIONS = {
    basePath: null,
    map: {
      '🏠': 'icon_home.png',
      '💳': 'icon_wallet.png',
      '🎁': 'icon_refer.png',
      '📋': 'icon_history.png',
      '👤': 'icon_profile.png',
      '🔑': 'icon_login.png',
      '📝': 'icon_register.png',
      '🎲': 'icon_game.png',
      '🏆': 'icon_trophy.png',
      '✅': 'icon_ok.png',
      '❌': 'icon_error.png',
      '🔒': 'icon_lock.png'
    },
    imgClass: 'emoji-img',
    imgSize: 20,
    replaceStandalone: true,
    replaceLeading: true,
    replaceInline: false,
    maxReplacesPerNode: 5,
    observeMutations: true,
    mutationObserverOptions: { childList: true, subtree: true }
  };

  function buildEmojiRegex(keys, flags = 'g') {
    const sorted = keys.slice().sort((a, b) => b.length - a.length);
    const pattern = sorted.map(k => k.replace(/([.*+?^${}()|\[\]\/\\])/g, '\\$1')).join('|');
    return new RegExp(pattern, flags);
  }

  function detectBasePath() {
    // ROOT FIX: Better base path detection for root deployment
    try {
      const scripts = document.getElementsByTagName('script');
      for (let i = scripts.length - 1; i >= 0; i--) {
        const s = scripts[i].src || '';
        if (s && s.indexOf('emoji-replacer.js') !== -1) {
          const idx = s.lastIndexOf('/');
          if (idx !== -1) return s.substring(0, idx) + '/../images/';
        }
      }
    } catch (e) {}
    // Fallback: assets/images/ at site root
    const origin = window.location.origin;
    const path = window.location.pathname;
    const dir = path.substring(0, path.lastIndexOf('/')) || '/';
    if (dir === '/' || dir === '') {
      return origin + '/assets/images/';
    }
    // Remove any project subdirectory
    return origin + '/assets/images/';
  }

  const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'CODE', 'PRE', 'TEXTAREA', 'INPUT', 'OPTION', 'SELECT', 'SVG']);

  function isSkippableNode(node) {
    if (!node || !node.parentNode) return true;
    const p = node.parentNode;
    if (!p.tagName) return false;
    if (SKIP_TAGS.has(p.tagName)) return true;
    if (p.isContentEditable) return true;
    return false;
  }

  function processTextNode(textNode, emojiRegex, options, map, basePath) {
    if (!textNode || !textNode.nodeValue) return 0;
    if (isSkippableNode(textNode)) return 0;

    let text = textNode.nodeValue;
    let replaces = 0;

    if (options.replaceStandalone) {
      const trimmed = text.trim();
      if (map[trimmed]) {
        const img = createImgElement(map[trimmed], basePath, options, trimmed);
        textNode.parentNode.replaceChild(img, textNode);
        return 1;
      }
    }

    if (options.replaceLeading) {
      const m = text.match(emojiRegex);
      if (m && m.index === 0) {
        const key = m[0];
        if (map[key]) {
          const img = createImgElement(map[key], basePath, options, key);
          const remainder = text.substring(key.length);
          const frag = document.createDocumentFragment();
          frag.appendChild(img);
          if (remainder.length > 0) frag.appendChild(document.createTextNode(remainder));
          textNode.parentNode.replaceChild(frag, textNode);
          return 1;
        }
      }
    }

    if (options.replaceInline) {
      const rx = emojiRegex;
      let lastIndex = 0;
      let match;
      const frag = document.createDocumentFragment();
      rx.lastIndex = 0;
      while ((match = rx.exec(text)) && replaces < options.maxReplacesPerNode) {
        const idx = match.index;
        const key = match[0];
        if (idx > lastIndex) {
          frag.appendChild(document.createTextNode(text.substring(lastIndex, idx)));
        }
        if (map[key]) {
          frag.appendChild(createImgElement(map[key], basePath, options, key));
          replaces++;
        } else {
          frag.appendChild(document.createTextNode(key));
        }
        lastIndex = idx + key.length;
      }
      if (replaces > 0) {
        if (lastIndex < text.length) frag.appendChild(document.createTextNode(text.substring(lastIndex)));
        textNode.parentNode.replaceChild(frag, textNode);
        return replaces;
      }
    }

    return 0;
  }

  function createImgElement(filenameOrUrl, basePath, options, emojiChar) {
    const img = document.createElement('img');
    const src = filenameOrUrl.indexOf('http') === 0 || filenameOrUrl.indexOf('/') === 0
      ? filenameOrUrl
      : (basePath + filenameOrUrl);
    img.src = src;
    img.alt = emojiChar || '';
    img.className = options.imgClass || 'emoji-img';
    img.width = options.imgSize || 20;
    img.height = options.imgSize || 20;
    img.loading = 'lazy';
    img.decoding = 'async';
    img.style.display = 'inline-block';
    img.style.verticalAlign = 'middle';
    return img;
  }

  function preloadImages(map, basePath) {
    const keys = Object.keys(map || {});
    keys.forEach(k => {
      const v = map[k];
      const img = new Image();
      img.src = (v.indexOf('http') === 0 || v.indexOf('/') === 0) ? v : (basePath + v);
    });
  }

  function walkAndReplace(root, emojiRegex, options, map, basePath) {
    let total = 0;
    try {
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
      const nodesToProcess = [];
      while (walker.nextNode()) {
        nodesToProcess.push(walker.currentNode);
      }
      for (let i = 0; i < nodesToProcess.length; i++) {
        total += processTextNode(nodesToProcess[i], emojiRegex, options, map, basePath);
      }
    } catch (e) {}
    return total;
  }

  const EmojiReplacer = {
    _options: null,
    _map: null,
    _emojiRegex: null,
    _basePath: null,
    _observer: null,
    _running: false,

    init: function (opts) {
      this._options = Object.assign({}, DEFAULT_OPTIONS, opts || {});
      this._basePath = this._options.basePath || detectBasePath();
      this._map = Object.assign({}, DEFAULT_OPTIONS.map, this._options.map || {});
      this._emojiRegex = buildEmojiRegex(Object.keys(this._map));
      preloadImages(this._map, this._basePath);
      this.start();
      return this;
    },

    start: function () {
      if (this._running) return this;
      try {
        walkAndReplace(document.body, this._emojiRegex, this._options, this._map, this._basePath);
      } catch (e) {}
      if (this._options.observeMutations && window.MutationObserver) {
        this._observer = new MutationObserver(mutations => {
          for (const m of mutations) {
            if (m.addedNodes && m.addedNodes.length) {
              m.addedNodes.forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                  processTextNode(node, this._emojiRegex, this._options, this._map, this._basePath);
                } else if (node.nodeType === Node.ELEMENT_NODE) {
                  walkAndReplace(node, this._emojiRegex, this._options, this._map, this._basePath);
                }
              });
            }
            if (m.type === 'characterData' && m.target && m.target.nodeType === Node.TEXT_NODE) {
              processTextNode(m.target, this._emojiRegex, this._options, this._map, this._basePath);
            }
          }
        });
        this._observer.observe(document.body, this._options.mutationObserverOptions);
      }
      this._running = true;
      return this;
    },

    stop: function () {
      if (this._observer) { this._observer.disconnect(); this._observer = null; }
      this._running = false;
      return this;
    },

    updateMap: function (newMap) {
      this._map = Object.assign({}, this._map, newMap || {});
      this._emojiRegex = buildEmojiRegex(Object.keys(this._map));
      preloadImages(this._map, this._basePath);
      return this;
    },

    replaceNow: function (rootElement) {
      rootElement = rootElement || document.body;
      return walkAndReplace(rootElement, this._emojiRegex, this._options, this._map, this._basePath);
    }
  };

  global.EmojiReplacer = EmojiReplacer;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      try { window.EmojiReplacer.init(); } catch (e) {}
    });
  } else {
    try { window.EmojiReplacer.init(); } catch (e) {}
  }

})(window);