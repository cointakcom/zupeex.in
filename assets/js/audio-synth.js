/**
 * ======================================================
 * AUDIO-SYNTH.JS - ZERO-DEPENDENCY WEB AUDIO SYNTHESIZER
 * Ludo Tournament Platform - Procedural Sound Engine
 * Version: 1.0.1 - ROOT DEPLOYMENT FIX
 * ======================================================
 */

(function() {
    'use strict';

    const LudoAudioEngine = {
        _ctx: null,
        _initialized: false,
        _resumed: false,
        _masterGain: null,
        _masterVolume: 0.8,
        _workletNode: null,

        init: function() {
            try {
                if (this._initialized && this._ctx && this._ctx.state === 'running') {
                    return true;
                }

                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) {
                    console.warn('LudoAudioEngine: Web Audio API not supported');
                    return false;
                }

                this._ctx = new AudioContext({
                    latencyHint: 'interactive',
                    sampleRate: 44100
                });

                this._masterGain = this._ctx.createGain();
                this._masterGain.gain.value = this._masterVolume;
                this._masterGain.connect(this._ctx.destination);

                this._ctx.onstatechange = function() {
                    if (this._ctx.state === 'running') {
                        this._resumed = true;
                    }
                }.bind(this);

                this._initialized = true;
                return true;
            } catch (e) {
                console.error('LudoAudioEngine: Init error:', e);
                return false;
            }
        },

        resume: function() {
            return new Promise((resolve) => {
                if (!this._initialized) {
                    this.init();
                }
                if (!this._ctx) { resolve(false); return; }
                if (this._ctx.state === 'running') { this._resumed = true; resolve(true); return; }
                this._ctx.resume().then(() => {
                    this._resumed = true;
                    resolve(true);
                }).catch(() => { resolve(false); });
            });
        },

        isReady: function() {
            return this._initialized && this._ctx && this._ctx.state === 'running';
        },

        ensureReady: function() {
            if (this.isReady()) { return Promise.resolve(true); }
            return this.resume();
        },

        setVolume: function(volume) {
            this._masterVolume = Math.max(0, Math.min(1, volume));
            if (this._masterGain) {
                this._masterGain.gain.setTargetAtTime(this._masterVolume, this._ctx ? this._ctx.currentTime : 0, 0.05);
            }
        },

        getVolume: function() { return this._masterVolume; },

        playClick: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();
                    osc.type = 'triangle';
                    osc.frequency.value = 880;
                    gain.gain.setValueAtTime(0.15 * this._masterVolume, now);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.06);
                    osc.connect(gain);
                    gain.connect(this._masterGain);
                    osc.start(now);
                    osc.stop(now + 0.06);
                } catch (e) {}
            });
        },

        playDiceRoll: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.4;
                    const bufferSize = Math.floor(this._ctx.sampleRate * duration);
                    const buffer = this._ctx.createBuffer(1, bufferSize, this._ctx.sampleRate);
                    const data = buffer.getChannelData(0);
                    for (let i = 0; i < bufferSize; i++) {
                        const progress = i / bufferSize;
                        const envelope = Math.pow(1 - progress, 1.5);
                        data[i] = (Math.random() * 2 - 1) * envelope;
                    }
                    const noise = this._ctx.createBufferSource();
                    noise.buffer = buffer;
                    const filter = this._ctx.createBiquadFilter();
                    filter.type = 'bandpass';
                    filter.frequency.value = 1800;
                    filter.Q.value = 0.8;
                    const gainNoise = this._ctx.createGain();
                    gainNoise.gain.setValueAtTime(0.3 * this._masterVolume, now);
                    gainNoise.gain.exponentialRampToValueAtTime(0.001, now + duration);
                    const lfo = this._ctx.createOscillator();
                    const lfoGain = this._ctx.createGain();
                    lfo.type = 'square';
                    lfo.frequency.value = 60 + Math.random() * 40;
                    lfoGain.gain.value = 0.15;
                    lfo.connect(lfoGain);
                    lfoGain.connect(filter.frequency);
                    noise.connect(filter);
                    filter.connect(gainNoise);
                    gainNoise.connect(this._masterGain);
                    noise.start(now);
                    noise.stop(now + duration);
                    lfo.start(now);
                    lfo.stop(now + duration);
                } catch (e) {}
            });
        },

        playTokenMove: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.15;
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(300, now);
                    osc.frequency.exponentialRampToValueAtTime(900, now + duration);
                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.12 * this._masterVolume, now + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration);
                    osc.connect(gain);
                    gain.connect(this._masterGain);
                    osc.start(now);
                    osc.stop(now + duration);
                } catch (e) {}
            });
        },

        playWin: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const notes = [
                        { freq: 523.25, start: 0, dur: 0.15 },
                        { freq: 659.25, start: 0.12, dur: 0.15 },
                        { freq: 783.99, start: 0.24, dur: 0.15 },
                        { freq: 1046.50, start: 0.36, dur: 0.20 },
                        { freq: 783.99, start: 0.52, dur: 0.15 },
                        { freq: 659.25, start: 0.64, dur: 0.18 },
                        { freq: 523.25, start: 0.76, dur: 0.25 }
                    ];
                    notes.forEach((note) => {
                        const osc = this._ctx.createOscillator();
                        const gain = this._ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = note.freq;
                        const startTime = now + note.start;
                        const endTime = startTime + note.dur;
                        const vol = (note.dur > 0.2) ? 0.18 : 0.14;
                        gain.gain.setValueAtTime(0.001, startTime);
                        gain.gain.linearRampToValueAtTime(vol * this._masterVolume, startTime + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.001, endTime);
                        osc.connect(gain);
                        gain.connect(this._masterGain);
                        osc.start(startTime);
                        osc.stop(endTime + 0.01);
                    });
                } catch (e) {}
            });
        },

        playLose: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.5;
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();
                    osc.type = 'sawtooth';
                    osc.detune.value = -12;
                    osc.frequency.setValueAtTime(440, now);
                    osc.frequency.exponentialRampToValueAtTime(349.23, now + duration);
                    const lfo = this._ctx.createOscillator();
                    const lfoGain = this._ctx.createGain();
                    lfo.type = 'sine';
                    lfo.frequency.value = 4;
                    lfoGain.gain.value = 0.3;
                    lfo.connect(lfoGain);
                    lfoGain.connect(gain.gain);
                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.08 * this._masterVolume, now + 0.08);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration);
                    const osc2 = this._ctx.createOscillator();
                    const gain2 = this._ctx.createGain();
                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(220, now);
                    osc2.frequency.exponentialRampToValueAtTime(174.61, now + duration);
                    gain2.gain.setValueAtTime(0.001, now);
                    gain2.gain.linearRampToValueAtTime(0.05 * this._masterVolume, now + 0.06);
                    gain2.gain.exponentialRampToValueAtTime(0.001, now + duration);
                    osc.connect(gain);
                    gain.connect(this._masterGain);
                    osc2.connect(gain2);
                    gain2.connect(this._masterGain);
                    osc.start(now);
                    osc.stop(now + duration + 0.02);
                    osc2.start(now);
                    osc2.stop(now + duration + 0.02);
                    lfo.start(now);
                    lfo.stop(now + duration);
                } catch (e) {}
            });
        },

        playGameStart: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const notes = [
                        { freq: 523.25, start: 0, dur: 0.12 },
                        { freq: 659.25, start: 0.10, dur: 0.12 },
                        { freq: 783.99, start: 0.20, dur: 0.15 }
                    ];
                    notes.forEach((note) => {
                        const osc = this._ctx.createOscillator();
                        const gain = this._ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = note.freq;
                        const startTime = now + note.start;
                        gain.gain.setValueAtTime(0.001, startTime);
                        gain.gain.linearRampToValueAtTime(0.1 * this._masterVolume, startTime + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.001, startTime + note.dur);
                        osc.connect(gain);
                        gain.connect(this._masterGain);
                        osc.start(startTime);
                        osc.stop(startTime + note.dur);
                    });
                } catch (e) {}
            });
        },

        playGameEnd: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = 392;
                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.12 * this._masterVolume, now + 0.04);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.4);
                    osc.connect(gain);
                    gain.connect(this._masterGain);
                    osc.start(now);
                    osc.stop(now + 0.4);
                } catch (e) {}
            });
        },

        playCapture: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.08;
                    const osc1 = this._ctx.createOscillator();
                    const gain1 = this._ctx.createGain();
                    osc1.type = 'square';
                    osc1.frequency.setValueAtTime(600, now);
                    osc1.frequency.exponentialRampToValueAtTime(200, now + duration);
                    gain1.gain.setValueAtTime(0.1 * this._masterVolume, now);
                    gain1.gain.exponentialRampToValueAtTime(0.001, now + duration);
                    osc1.connect(gain1);
                    gain1.connect(this._masterGain);
                    const osc2 = this._ctx.createOscillator();
                    const gain2 = this._ctx.createGain();
                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(900, now);
                    osc2.frequency.exponentialRampToValueAtTime(400, now + duration);
                    gain2.gain.setValueAtTime(0.06 * this._masterVolume, now);
                    gain2.gain.exponentialRampToValueAtTime(0.001, now + duration);
                    osc2.connect(gain2);
                    gain2.connect(this._masterGain);
                    osc1.start(now);
                    osc1.stop(now + duration);
                    osc2.start(now);
                    osc2.stop(now + duration);
                } catch (e) {}
            });
        },

        playNotification: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const notes = [
                        { freq: 659.25, start: 0, dur: 0.08 },
                        { freq: 523.25, start: 0.10, dur: 0.08 }
                    ];
                    notes.forEach((note) => {
                        const osc = this._ctx.createOscillator();
                        const gain = this._ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = note.freq;
                        const startTime = now + note.start;
                        gain.gain.setValueAtTime(0.001, startTime);
                        gain.gain.linearRampToValueAtTime(0.08 * this._masterVolume, startTime + 0.01);
                        gain.gain.exponentialRampToValueAtTime(0.001, startTime + note.dur);
                        osc.connect(gain);
                        gain.connect(this._masterGain);
                        osc.start(startTime);
                        osc.stop(startTime + note.dur);
                    });
                } catch (e) {}
            });
        },

        playError: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;
                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.3;
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(400, now);
                    osc.frequency.exponentialRampToValueAtTime(200, now + duration);
                    osc.detune.value = -25;
                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.06 * this._masterVolume, now + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration);
                    osc.connect(gain);
                    gain.connect(this._masterGain);
                    osc.start(now);
                    osc.stop(now + duration);
                } catch (e) {}
            });
        },

        isSupported: function() {
            return !!(window.AudioContext || window.webkitAudioContext);
        },

        getState: function() {
            return this._ctx ? this._ctx.state : 'uninitialized';
        },

        suspend: function() {
            if (this._ctx && this._ctx.state === 'running') {
                this._ctx.suspend();
            }
        },

        autoInit: function() {
            const initFn = function() {
                LudoAudioEngine.init();
                LudoAudioEngine.resume().then(() => {
                    setTimeout(() => { LudoAudioEngine.playClick(); }, 100);
                });
                document.removeEventListener('click', initFn);
                document.removeEventListener('touchstart', initFn);
                document.removeEventListener('keydown', initFn);
            };
            document.addEventListener('click', initFn);
            document.addEventListener('touchstart', initFn);
            document.addEventListener('keydown', initFn);
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                setTimeout(() => { if (!this._initialized) { this.init(); } }, 100);
            }
        }
    };

    window.LudoAudioEngine = LudoAudioEngine;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { LudoAudioEngine.autoInit(); });
    } else {
        LudoAudioEngine.autoInit();
    }

    console.log('LudoAudioEngine: Loaded successfully');
})();