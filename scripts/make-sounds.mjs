// Makes Burnfront's sound effects from scratch: no recordings, no samples,
// only sine waves and filtered noise built here. So the sounds are our own
// and need no licence. Every sound is short, soft and low in pitch (no shrill
// beeps), for players who do not want to be startled.
//
// Run from the repository root:  node scripts/make-sounds.mjs
// It writes the same WAV files for the website (resources/sounds) and the
// app (apps/mobile/assets/sounds). Change a sound here, never by hand.

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RATE = 22050; // plenty for soft sounds, half the file size of 44.1 kHz
const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = [join(root, 'resources/sounds'), join(root, 'apps/mobile/assets/sounds')];

// Seeded noise, so running the script again gives byte-identical files.
let seed = 0x2f6b1d;
function noise() {
    seed = (seed * 1664525 + 1013904223) >>> 0;
    return seed / 2 ** 31 - 1;
}

const buffer = (seconds) => new Float32Array(Math.round(seconds * RATE));

/** A soft bell-like note: a sine with two quiet overtones, quick attack, long decay. */
function bell(out, at, freq, { gain = 1, decay = 0.35, length = 0.9 } = {}) {
    const start = Math.round(at * RATE);
    const n = Math.round(length * RATE);
    for (let i = 0; i < n && start + i < out.length; i++) {
        const t = i / RATE;
        const attack = Math.min(1, t / 0.006);
        const env = attack * Math.exp(-t / decay);
        const w = 2 * Math.PI * freq * t;
        const v = Math.sin(w) + 0.28 * Math.sin(2 * w) * Math.exp(-t / (decay * 0.5)) + 0.08 * Math.sin(3 * w) * Math.exp(-t / (decay * 0.3));
        out[start + i] += gain * env * v;
    }
}

/** A short wooden knock: a sine that drops in pitch, plus a tiny burst of noise. */
function knock(out, at, freq, { gain = 1, decay = 0.045, drop = 0.35, click = 0.25 } = {}) {
    const start = Math.round(at * RATE);
    let phase = 0;
    let lp = 0;
    for (let i = 0; start + i < out.length; i++) {
        const t = i / RATE;
        const env = Math.min(1, t / 0.002) * Math.exp(-t / decay);
        if (env < 1e-4 && t > 0.01) break;
        const f = freq * (1 - drop + drop * Math.exp(-t / 0.02));
        phase += (2 * Math.PI * f) / RATE;
        lp += 0.35 * (noise() - lp); // soften the click
        out[start + i] += gain * (env * Math.sin(phase) + click * lp * Math.exp(-t / 0.006));
    }
}

/** A gentle glide from one pitch to another, for taking something back. */
function glide(out, at, from, to, { gain = 1, length = 0.12 } = {}) {
    const start = Math.round(at * RATE);
    const n = Math.round(length * RATE);
    let phase = 0;
    for (let i = 0; i < n && start + i < out.length; i++) {
        const t = i / RATE;
        const k = t / length;
        const f = from * Math.pow(to / from, k);
        phase += (2 * Math.PI * f) / RATE;
        const env = Math.min(1, t / 0.004) * Math.pow(1 - k, 2);
        out[start + i] += gain * env * Math.sin(phase);
    }
}

/** The fire running across the board: a warm rush of filtered noise with small crackles. */
function burn(out, { length = 1.2, gain = 1 } = {}) {
    const n = Math.round(length * RATE);
    let low = 0;
    let low2 = 0;
    for (let i = 0; i < n && i < out.length; i++) {
        const t = i / RATE;
        const k = t / length;
        // swell in, then die down
        const env = Math.sin(Math.PI * Math.min(1, k * 1.6) ** 0.7 * 0.5) * Math.pow(1 - k, 1.6);
        const cutoff = 0.05 + 0.1 * env; // gets a little brighter as it grows
        low += cutoff * (noise() - low);
        low2 += cutoff * (low - low2);
        out[i] += gain * 2.6 * env * low2;
    }
    // crackles: little pops, most of them in the middle of the rush
    for (let c = 0; c < 26; c++) {
        const at = length * 0.75 * Math.pow((noise() + 1) / 2, 1.2);
        const k = at / length;
        const loud = Math.sin(Math.PI * Math.min(1, k * 1.6) ** 0.7 * 0.5) * Math.pow(1 - k, 1.6);
        knock(out, at, 900 + 900 * ((noise() + 1) / 2), { gain: gain * 0.22 * loud, decay: 0.006, drop: 0.2, click: 0.6 });
    }
}

/** Scales the loudest point to `peak`, fades the ends and returns 16-bit samples. */
function finish(samples, peak) {
    let max = 0;
    for (const s of samples) max = Math.max(max, Math.abs(s));
    const scale = max > 0 ? peak / max : 0;
    const fade = Math.round(0.004 * RATE);
    const pcm = new Int16Array(samples.length);
    for (let i = 0; i < samples.length; i++) {
        let v = samples[i] * scale;
        if (i < fade) v *= i / fade;
        if (i > samples.length - fade) v *= (samples.length - i) / fade;
        pcm[i] = Math.max(-32767, Math.min(32767, Math.round(v * 32767)));
    }
    return pcm;
}

function wav(pcm) {
    const data = Buffer.from(pcm.buffer);
    const head = Buffer.alloc(44);
    head.write('RIFF', 0);
    head.writeUInt32LE(36 + data.length, 4);
    head.write('WAVE', 8);
    head.write('fmt ', 12);
    head.writeUInt32LE(16, 16);
    head.writeUInt16LE(1, 20); // PCM
    head.writeUInt16LE(1, 22); // mono
    head.writeUInt32LE(RATE, 24);
    head.writeUInt32LE(RATE * 2, 28);
    head.writeUInt16LE(2, 32);
    head.writeUInt16LE(16, 34);
    head.write('data', 36);
    head.writeUInt32LE(data.length, 40);
    return Buffer.concat([head, data]);
}

// Note pitches (Hz)
const C5 = 523.25, E5 = 659.25, G5 = 783.99, A4 = 440, C6 = 1046.5, G4 = 392, E4 = 329.63;

const sounds = {
    // A firebreak goes down: a soft wooden "tok".
    place: () => {
        const b = buffer(0.16);
        // low enough to sound like wood, with a quiet overtone so a phone speaker still carries it
        knock(b, 0, 400, { decay: 0.05 });
        knock(b, 0, 800, { decay: 0.03, gain: 0.3, click: 0 });
        return finish(b, 0.42);
    },
    // A dot ("no firebreak here"): a lighter, quieter tap.
    dot: () => {
        const b = buffer(0.1);
        knock(b, 0, 620, { decay: 0.025, click: 0.15 });
        return finish(b, 0.28);
    },
    // A square is cleared, a move is undone, or the board is reset.
    clear: () => {
        const b = buffer(0.14);
        glide(b, 0, 480, 300, { length: 0.12 });
        return finish(b, 0.26);
    },
    // A hint placed a firebreak for you: two calm bell notes going up.
    hint: () => {
        const b = buffer(0.75);
        bell(b, 0, E5, { decay: 0.22 });
        bell(b, 0.11, A4 * 2, { decay: 0.3, gain: 0.8 });
        return finish(b, 0.32);
    },
    // Something is not right yet (all firebreaks used, or a hint found a wrong one):
    // two low notes going down. A soft "hmm", never a buzzer.
    wrong: () => {
        const b = buffer(0.6);
        bell(b, 0, G4, { decay: 0.16 });
        bell(b, 0.13, E4, { decay: 0.22 });
        return finish(b, 0.32);
    },
    // The fire runs through the board once it is contained.
    burn: () => {
        const b = buffer(1.25);
        burn(b, { length: 1.25 });
        return finish(b, 0.34);
    },
    // Contained: a short, warm rising chord.
    solved: () => {
        const b = buffer(1.6);
        bell(b, 0, C5, { decay: 0.5, gain: 0.9 });
        bell(b, 0.12, E5, { decay: 0.5, gain: 0.85 });
        bell(b, 0.24, G5, { decay: 0.55, gain: 0.8 });
        bell(b, 0.4, C6, { decay: 0.6, gain: 0.55 });
        return finish(b, 0.4);
    },
};

for (const dir of OUT) mkdirSync(dir, { recursive: true });
for (const [name, make] of Object.entries(sounds)) {
    seed = 0x2f6b1d; // each sound gets the same noise, whatever the order
    const file = wav(make());
    for (const dir of OUT) writeFileSync(join(dir, `${name}.wav`), file);
    console.log(`${name}.wav  ${(file.length / 1024).toFixed(1)} KB`);
}
