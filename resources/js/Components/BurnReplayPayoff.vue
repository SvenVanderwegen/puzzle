<script setup>
/* The solve-payoff hero: the same burn-reconstruction replay the Start screen
   runs (see BurnReplayHero.vue), but drawn from the incident the player just
   solved rather than a scripted one. The reconstructed firebreak line is cut
   in around the scar, the spark pulses, the burn replays from it one minute
   per beat with the same flame→ember→char cooling ramp, and each clue's sensor
   ping checks off exactly when the front's own arrival time reaches it — then
   the record is stamped and the sheet wipes for the next loop.

   Everything is driven by the engine's own burn-time array (validate()'s BFS
   distances, passed as `times`): times[i] >= 0 is the minute the fire reached
   cell i, times[i] < 0 marks a firebreak. So the replay can't disagree with the
   board the player actually contained. Reduced-motion holds the stamped frame. */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import RubberStamp from '@/Components/RubberStamp.vue';

const props = defineProps({
    rows: { type: Number, required: true },
    cols: { type: Number, required: true },
    spark: { type: Number, required: true },
    /* Per-cell burn arrival minute from validate(); < 0 is a firebreak. */
    times: { type: Array, default: () => [] },
    /* [[cellIndex, minute], ...] — the clues, drawn as sensor pings. */
    clues: { type: Array, default: () => [] },
    label: { type: String, default: 'Contained' },
});

/* Sheet coordinate system (survey grid fills it; the board sits centred). */
const VW = 1000;
const VH = 424;

/* Flame mark, same path as FlameGlyph.vue (24×24 viewBox). */
const FLAME =
    'M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z';

const PERIOD = 12000;
const P_PING0 = 0.02; // first ping fades in
const P_PING_STEP = 0.02;
const P_BRK0 = 0.07; // break line starts being cut in
const P_BRK1 = 0.21;
const P_SPARK = 0.22; // spark pulses in
const P_BURN0 = 0.27; // replay window
const P_BURN1 = 0.7;
const P_STAMP = 0.74; // record stamped
const P_FADE0 = 0.9; // sheet wipes for the next loop
const P_FADE1 = 0.965;

const clamp01 = (v) => Math.max(0, Math.min(1, v));
const smooth = (t) => t * t * (3 - 2 * t);

function hexLerp(a, b, t) {
    const c = (h, i) => parseInt(h.slice(i, i + 2), 16);
    const m = (i) => Math.round(c(a, i) + (c(b, i) - c(a, i)) * t);
    return `rgb(${m(1)},${m(3)},${m(5)})`;
}

/* Cooling ramp for a burnt cell, indexed by age in quarter-minutes:
   flame flash → ember → cooled char, then hold. (Same stops as the hero.) */
const RAMP_STOPS = [
    [0, '#ffd06b'],
    [0.4, '#ff9a4d'],
    [1.4, '#b03f16'],
    [3.2, '#6b2410'],
    [6, '#451607'],
];
const RAMP_N = 28;
const RAMP = Array.from({ length: RAMP_N }, (_, k) => {
    const a = k / 4;
    let i = 0;
    while (i < RAMP_STOPS.length - 2 && a > RAMP_STOPS[i + 1][0]) i++;
    const [a0, c0] = RAMP_STOPS[i];
    const [a1, c1] = RAMP_STOPS[i + 1];
    return hexLerp(c0, c1, clamp01((a - a0) / (a1 - a0)));
});

function buildModel() {
    const R = props.rows,
        C = props.cols,
        n = R * C;
    const times = props.times;

    /* Centre the board in the sheet; cells sized so any grid (5×5…8×8) fills a
       consistent ~square footprint regardless of dimensions. */
    const CELL = Math.min((VW * 0.6) / Math.max(1, C), (VH * 0.72) / Math.max(1, R));
    const OX = (VW - C * CELL) / 2;
    const OY = (VH - R * CELL) / 2;

    const ok = Number.isFinite(n) && n > 0 && times && times.length === n;

    const cells = []; // burnt ground, replays with the cooling ramp
    const breaks = []; // firebreaks, hatched
    let maxT = 1;
    let sparkX = props.spark % C,
        sparkY = Math.floor(props.spark / C);

    if (ok) {
        for (let idx = 0; idx < n; idx++) {
            const x = idx % C,
                y = Math.floor(idx / C);
            if (idx === props.spark) continue;
            if (times[idx] < 0) {
                breaks.push({ x, y, el: null, q: -1 });
            } else {
                cells.push({ x, y, time: times[idx], el: null, k: -2 });
                if (times[idx] > maxT) maxT = times[idx];
            }
        }
        /* Cut the ring in as a single sweep: order the trench cells by bearing
           from the spark. */
        breaks.sort(
            (a, b) => Math.atan2(a.y - sparkY, a.x - sparkX) - Math.atan2(b.y - sparkY, b.x - sparkX),
        );
    }

    /* Sensor pings = the clues; their timestamps are the board's own arrival
       times, so on a solved board every ping checks off exactly on schedule. */
    const pings = (props.clues || [])
        .map(([idx, minute]) => ({
            x: idx % C,
            y: Math.floor(idx / C),
            time: ok && times[idx] >= 0 ? times[idx] : minute,
            el: null,
            checkEl: null,
            dotEl: null,
            labelEl: null,
            q: -1,
            checked: null,
        }))
        .filter((p) => p.x >= 0 && p.x < C && p.y >= 0 && p.y < R);

    return { ok, cells, breaks, pings, maxT, CELL, OX, OY, sparkX, sparkY };
}

const model = buildModel();
const beatP = (P_BURN1 - P_BURN0) / (model.maxT + 0.001);

const cx = (x) => model.OX + x * model.CELL;
const cy = (y) => model.OY + y * model.CELL;
const half = model.CELL / 2;
/* Inset a drawn cell a hair inside its survey square. */
const inset = Math.max(1.5, model.CELL * 0.06);
const box = model.CELL - inset * 2;

const sceneG = ref(null);
const sparkG = ref(null);
const displayMinute = ref(0);
const stampOn = ref(false);

/* Map-sheet texture: survey grid aligned to the board cells (so each square is
   one cell), a heavier line every fifth, spanning the whole sheet. */
let minorGrid = '';
let majorGrid = '';
{
    const kx0 = Math.ceil(-model.OX / model.CELL),
        kx1 = Math.floor((VW - model.OX) / model.CELL);
    for (let k = kx0; k <= kx1; k++) {
        const d = `M${(model.OX + k * model.CELL).toFixed(1)},0 V${VH} `;
        if (((k % 5) + 5) % 5 === 0) majorGrid += d;
        else minorGrid += d;
    }
    const ky0 = Math.ceil(-model.OY / model.CELL),
        ky1 = Math.floor((VH - model.OY) / model.CELL);
    for (let k = ky0; k <= ky1; k++) {
        const d = `M0,${(model.OY + k * model.CELL).toFixed(1)} H${VW} `;
        if (((k % 5) + 5) % 5 === 0) majorGrid += d;
        else minorGrid += d;
    }
}

let rafId = null;
let sparkQ = -1;
let fadeQ = -1;

function paint(p) {
    for (let i = 0; i < model.pings.length; i++) {
        const pg = model.pings[i];
        const q = Math.round(clamp01((p - (P_PING0 + i * P_PING_STEP)) / 0.012) * 20);
        if (q !== pg.q) {
            pg.q = q;
            pg.el?.setAttribute('opacity', (q / 20).toFixed(2));
        }
        const checked = p >= P_BURN0 + (pg.time + 0.35) * beatP && p < P_FADE1;
        if (checked !== pg.checked) {
            pg.checked = checked;
            pg.checkEl?.setAttribute('opacity', checked ? '1' : '0');
            pg.dotEl?.setAttribute('opacity', checked ? '0' : '1');
            pg.el?.setAttribute('stroke', checked ? '#7fb0b8' : '#8a7c66');
            pg.labelEl?.setAttribute('fill', checked ? '#a9ccd2' : '#b6a890');
        }
    }

    for (let i = 0; i < model.breaks.length; i++) {
        const b = model.breaks[i];
        const at = P_BRK0 + (model.breaks.length ? i / model.breaks.length : 0) * (P_BRK1 - P_BRK0);
        const q = Math.round(clamp01((p - at) / 0.01) * 20);
        if (q !== b.q) {
            b.q = q;
            b.el?.setAttribute('opacity', ((q / 20) * 0.92).toFixed(2));
        }
    }

    const sq = Math.round(clamp01((p - P_SPARK) / 0.02) * 20);
    if (sq !== sparkQ) {
        sparkQ = sq;
        const t = sq / 20;
        sparkG.value?.setAttribute('opacity', t.toFixed(2));
        const s = 1 + Math.sin(t * Math.PI) * 0.3;
        sparkG.value?.setAttribute(
            'transform',
            `translate(${(cx(model.sparkX) + half).toFixed(1)} ${(cy(model.sparkY) + half).toFixed(1)}) scale(${s.toFixed(3)})`,
        );
    }

    const m = (p - P_BURN0) / beatP; // replay clock, in minutes
    for (const c of model.cells) {
        const age = m - c.time;
        const k = age < 0 ? -1 : Math.min(RAMP_N - 1, Math.floor(age * 4));
        if (k === c.k) continue;
        c.k = k;
        if (k < 0) {
            c.el?.setAttribute('opacity', '0');
        } else {
            c.el?.setAttribute('opacity', '1');
            c.el?.setAttribute('fill', RAMP[k]);
        }
    }

    const fq = Math.round((p < P_FADE0 ? 1 : 1 - smooth(clamp01((p - P_FADE0) / (P_FADE1 - P_FADE0)))) * 40);
    if (fq !== fadeQ) {
        fadeQ = fq;
        sceneG.value?.setAttribute('opacity', (fq / 40).toFixed(2));
    }

    displayMinute.value = p < P_BURN0 || p >= P_FADE0 ? 0 : Math.max(0, Math.min(model.maxT, Math.floor(m)));
    stampOn.value = p >= P_STAMP && p < P_FADE0;
}

function frame(ts, t0) {
    paint(((ts - t0) % PERIOD) / PERIOD);
    rafId = requestAnimationFrame((next) => frame(next, t0));
}

onMounted(() => {
    if (!model.ok) return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        paint(0.8); // the settled, stamped frame
        return;
    }
    rafId = requestAnimationFrame((ts) => frame(ts, ts));
});

onBeforeUnmount(() => {
    if (rafId) cancelAnimationFrame(rafId);
});
</script>

<template>
    <div class="relative h-full w-full bg-[#141010]">
        <svg viewBox="0 0 1000 424" preserveAspectRatio="xMidYMid slice" class="block h-full w-full" aria-hidden="true">
            <defs>
                <pattern id="bp-hatch" width="9" height="9" patternUnits="userSpaceOnUse" patternTransform="rotate(135)">
                    <rect width="9" height="9" fill="#241a12" />
                    <rect width="3" height="9" fill="#6f4c2b" />
                </pattern>
                <radialGradient id="bp-spark" cx="0.5" cy="0.62" r="0.72">
                    <stop offset="0" stop-color="#ff7a2d" stop-opacity="0.4" />
                    <stop offset="1" stop-color="#2a1a10" />
                </radialGradient>
            </defs>

            <!-- the map sheet: survey grid and a few terrain contours -->
            <path :d="minorGrid" stroke="#3a2f24" stroke-width="1" opacity="0.35" fill="none" />
            <path :d="majorGrid" stroke="#3a2f24" stroke-width="1" opacity="0.7" fill="none" />
            <g stroke="#4a3c2c" stroke-width="1.4" opacity="0.3" fill="none">
                <path d="M-20,118 C170,158 300,86 520,128 S810,168 1020,116" />
                <path d="M-20,224 C130,200 260,252 430,226 S740,190 1020,232" />
                <path d="M-20,330 C160,296 290,356 470,326 S800,282 1020,322" />
            </g>

            <g ref="sceneG">
                <!-- the burn replay -->
                <g>
                    <rect
                        v-for="c in model.cells"
                        :key="`c${c.x},${c.y}`"
                        :ref="(el) => (c.el = el)"
                        :x="cx(c.x) + inset"
                        :y="cy(c.y) + inset"
                        :width="box"
                        :height="box"
                        rx="2"
                        opacity="0"
                    />
                </g>

                <!-- the reconstructed break line -->
                <g>
                    <rect
                        v-for="b in model.breaks"
                        :key="`b${b.x},${b.y}`"
                        :ref="(el) => (b.el = el)"
                        :x="cx(b.x) + inset + 1"
                        :y="cy(b.y) + inset + 1"
                        :width="box - 2"
                        :height="box - 2"
                        rx="2"
                        fill="url(#bp-hatch)"
                        stroke="#8a6238"
                        stroke-width="1"
                        opacity="0"
                    />
                </g>

                <!-- the spark -->
                <g ref="sparkG" opacity="0" :transform="`translate(${cx(model.sparkX) + half} ${cy(model.sparkY) + half})`">
                    <rect :x="-box / 2" :y="-box / 2" :width="box" :height="box" rx="2" fill="url(#bp-spark)" stroke="#c85618" stroke-width="1" />
                    <g :transform="`translate(-10 -10) scale(${(box / 24).toFixed(3)})`" style="filter: drop-shadow(0 0 5px rgba(255, 122, 45, 0.55))">
                        <path :d="FLAME" fill="#ff7a2d" />
                        <path :d="FLAME" fill="#ffd36b" transform="translate(5.4 8.2) scale(0.55)" />
                    </g>
                </g>

                <!-- the sensor log: clue pings check off as the front reaches them -->
                <g
                    v-for="pg in model.pings"
                    :key="`p${pg.x},${pg.y}`"
                    :ref="(el) => (pg.el = el)"
                    :transform="`translate(${cx(pg.x) + half} ${cy(pg.y) + half})`"
                    opacity="0"
                    stroke="#8a7c66"
                    fill="none"
                >
                    <circle r="4.5" stroke-width="1.3" />
                    <circle :ref="(el) => (pg.dotEl = el)" r="1.3" fill="#b6a890" stroke="none" />
                    <path
                        :ref="(el) => (pg.checkEl = el)"
                        d="M-2.4,0.2 L-0.7,2 L2.8,-2.2"
                        stroke="#7fb0b8"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        opacity="0"
                    />
                    <text
                        :ref="(el) => (pg.labelEl = el)"
                        x="9"
                        y="-7"
                        fill="#b6a890"
                        stroke="none"
                        class="font-mono"
                        font-size="12.5"
                        letter-spacing="0.08em"
                    >
                        M+{{ String(pg.time).padStart(2, '0') }}
                    </text>
                </g>
            </g>
        </svg>

        <!-- captions live in HTML so slice-cropping never cuts them off -->
        <div
            class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3 font-mono text-[10px] tracking-[.12em] text-stock uppercase [text-shadow:0_1px_3px_#000]"
        >
            <div class="flex items-start justify-between">
                <span>Burn reconstruction · replay</span>
                <span class="text-ember-hi">One valid account</span>
            </div>
            <div class="flex items-end justify-end text-ash">
                <span>T+{{ String(displayMinute).padStart(2, '0') }} min</span>
            </div>
        </div>

        <div
            class="pointer-events-none absolute right-[7%] bottom-[15%] transition-[opacity,transform] duration-200 ease-out"
            :class="stampOn ? 'scale-100 opacity-95' : 'scale-125 opacity-0'"
        >
            <RubberStamp tone="ember" size="lg" :rotate="-8">{{ label }}</RubberStamp>
        </div>
    </div>
</template>
