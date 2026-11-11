/* Sound effects. Short, soft sounds made by scripts/make-sounds.mjs (our own,
   no licence needed): a tap for a firebreak or a dot, a glide for taking one
   back, a chime for a hint, a low "hmm" when something is not right yet, the
   fire running through the board and a chord when it is contained. Then the
   points: a light tick for each line counted, three notes for a new level
   and a short fanfare for a new rank.

   Sound is on unless the player switches it off; the choice is kept per
   browser under SOUND_KEY. Browsers only allow sound after the player has
   touched the page, so nothing ever plays on its own when a page opens. On an
   iPhone the "ambient" audio session keeps the silent switch working and
   lets the player's own music carry on. Every step is best-effort: when a
   browser has no Web Audio, or a file fails to load, the game simply stays
   quiet. */
import { ref } from 'vue';

export const SOUND_KEY = 'burnfront:sound';
const VOLUME = 0.7;

const files = import.meta.glob('../../sounds/*.wav', { eager: true, query: '?url', import: 'default' });
const urls = {};
for (const [path, url] of Object.entries(files)) urls[path.match(/([^/]+)\.wav$/)[1]] = url;

function readChoice() {
    try {
        return localStorage.getItem(SOUND_KEY) !== 'off';
    } catch (e) {
        return true;
    }
}

/** Whether sound effects are switched on. */
export const soundOn = ref(readChoice());

export function setSound(on) {
    soundOn.value = Boolean(on);
    try {
        if (soundOn.value) localStorage.removeItem(SOUND_KEY);
        else localStorage.setItem(SOUND_KEY, 'off');
    } catch (e) {
        /* storage blocked: the choice still holds for this page view */
    }
    if (soundOn.value) play('place');
}

export function toggleSound() {
    setSound(!soundOn.value);
}

let ctx = null;
let output = null;
const buffers = {};
let loading = null;

function context() {
    if (ctx) return ctx;
    const AudioCtx = typeof window !== 'undefined' ? window.AudioContext || window.webkitAudioContext : null;
    if (!AudioCtx) return null;
    try {
        // Safari 17+: follow the silent switch and mix with other audio.
        if (navigator.audioSession) navigator.audioSession.type = 'ambient';
    } catch (e) {
        /* not supported: fine */
    }
    try {
        ctx = new AudioCtx();
        output = ctx.createGain();
        output.gain.value = VOLUME;
        output.connect(ctx.destination);
    } catch (e) {
        ctx = null;
    }
    return ctx;
}

function load() {
    if (loading || !context()) return loading;
    loading = Promise.all(
        Object.entries(urls).map(async ([name, url]) => {
            try {
                const resp = await fetch(url);
                const data = await resp.arrayBuffer();
                buffers[name] = await new Promise((resolve, reject) => ctx.decodeAudioData(data, resolve, reject));
            } catch (e) {
                /* this one sound stays quiet */
            }
        }),
    );
    return loading;
}

/* The first touch or key press on a page wakes the audio up and loads the
   sounds, so the very first move already has its sound. */
function unlock() {
    if (!soundOn.value || !context()) return;
    if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    load();
}
if (typeof window !== 'undefined') {
    window.addEventListener('pointerdown', unlock, { capture: true, passive: true });
    window.addEventListener('keydown', unlock, { capture: true, passive: true });
}

/** Plays one sound: place, dot, clear, hint, wrong, burn, solved, points,
    level or rank. `rate` plays it higher (above 1) or lower (below 1). */
export function play(name, rate = 1) {
    if (!soundOn.value || typeof document === 'undefined' || document.hidden) return;
    if (!context()) return;
    if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    const buffer = buffers[name];
    if (!buffer) {
        /* Still loading (the very first move on a page): play it as soon as
           it is ready, unless that is too late to still belong to the move. */
        const asked = Date.now();
        load()?.then(() => {
            if (buffers[name] && Date.now() - asked < 400) play(name, rate);
        });
        return;
    }
    try {
        const source = ctx.createBufferSource();
        source.buffer = buffer;
        if (rate !== 1) source.playbackRate.value = rate;
        source.connect(output);
        source.start();
    } catch (e) {
        /* quiet on failure */
    }
}
