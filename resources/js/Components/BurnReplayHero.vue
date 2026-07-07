<script setup>
/* The Start-screen hero: a burn-reconstruction replay, drawn as a figure
   from the incident file itself. The sensor log pings in first, the
   reconstructed firebreak line is cut in around the scar (plus one interior
   spur the fire has to wrap), then the burn replays from the spark one
   minute per beat — each ping checked off as the front reaches it on
   schedule — the scar cools, the record is stamped CONTAINED, and the sheet
   wipes for the next loop.

   The scar footprint, the break ring and every ping timestamp are computed
   from one BFS over the same spread rule the game uses (orthogonal
   neighbours, one ring per minute), so the figure never lies about its own
   evidence. Reduced-motion holds the final stamped frame. */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import RubberStamp from '@/Components/RubberStamp.vue';

const COLS = 35;
const ROWS = 15;
const CELL = 28;
const OX = (1000 - COLS * CELL) / 2;
const OY = (424 - ROWS * CELL) / 2;
const SPARK = { x: 16, y: 7 };
const SPUR = { x: 21, maxY: 8 };

/* Flame mark, same path as FlameGlyph.vue (24×24 viewBox). */
const FLAME =
    'M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z';

const PERIOD = 14000;
const P_PING0 = 0.015; // first ping fades in
const P_PING_STEP = 0.018;
const P_BRK0 = 0.09; // break line starts being cut in
const P_BRK1 = 0.24;
const P_SPARK = 0.255; // spark pulses in
const P_BURN0 = 0.29; // replay window
const P_BURN1 = 0.66;
const P_STAMP = 0.7; // CONTAINED
const P_FADE0 = 0.9; // sheet wipes for the next loop
const P_FADE1 = 0.965;

const key = (x, y) => `${x},${y}`;
const clamp01 = (v) => Math.max(0, Math.min(1, v));
const smooth = (t) => t * t * (3 - 2 * t);

function hexLerp(a, b, t) {
    const c = (h, i) => parseInt(h.slice(i, i + 2), 16);
    const m = (i) => Math.round(c(a, i) + (c(b, i) - c(a, i)) * t);
    return `rgb(${m(1)},${m(3)},${m(5)})`;
}

/* Cooling ramp for a burnt cell, indexed by age in quarter-minutes:
   flame flash → ember → cooled char, then hold. */
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
    // Scar footprint: a wobbled ellipse around the spark.
    const inBlob = (x, y) => {
        const dx = (x - SPARK.x) / 10.5;
        const dy = (y - SPARK.y) / 3.9;
        const th = Math.atan2(dy, dx);
        return Math.hypot(dx, dy) < 1 + 0.13 * Math.sin(3 * th + 1.7) + 0.08 * Math.sin(5 * th + 0.4);
    };
    const region = new Set();
    for (let y = 0; y < ROWS; y++) for (let x = 0; x < COLS; x++) if (inBlob(x, y)) region.add(key(x, y));

    // An interior spur line hanging from the top of the ring: the front has
    // to wrap under its tip, which is what delays the pings in its shadow.
    let spurTop = -1;
    for (let y = 0; y <= SPUR.maxY; y++) {
        if (region.has(key(SPUR.x, y))) {
            spurTop = y;
            break;
        }
    }
    if (spurTop >= 0) for (let y = spurTop; y <= SPUR.maxY; y++) region.delete(key(SPUR.x, y));

    // Burn times: BFS from the spark, orthogonal, one ring per minute —
    // the same spread rule the game's clues encode.
    const NBR = [
        [1, 0],
        [-1, 0],
        [0, 1],
        [0, -1],
    ];
    const times = new Map([[key(SPARK.x, SPARK.y), 0]]);
    let frontier = [[SPARK.x, SPARK.y]];
    let minute = 0;
    while (frontier.length) {
        minute += 1;
        const next = [];
        for (const [x, y] of frontier) {
            for (const [dx, dy] of NBR) {
                const k = key(x + dx, y + dy);
                if (region.has(k) && !times.has(k)) {
                    times.set(k, minute);
                    next.push([x + dx, y + dy]);
                }
            }
        }
        frontier = next;
    }
    for (const k of [...region]) if (!times.has(k)) region.delete(k); // drop unreachable nubs

    // The break line: every off-scar cell touching the scar — the
    // containment ring plus the spur. Ring cells sort by bearing from the
    // spark so the line reads as being cut in one sweep; the spur is cut last.
    const ringSet = new Set();
    const ring = [];
    const spurCells = [];
    for (const k of region) {
        const [x, y] = k.split(',').map(Number);
        for (const [dx, dy] of NBR) {
            const nk = key(x + dx, y + dy);
            if (region.has(nk) || ringSet.has(nk)) continue;
            ringSet.add(nk);
            const cell = { x: x + dx, y: y + dy, el: null, q: -1 };
            (cell.x === SPUR.x && cell.y >= spurTop && cell.y <= SPUR.maxY ? spurCells : ring).push(cell);
        }
    }
    ring.sort((a, b) => Math.atan2(a.y - SPARK.y, a.x - SPARK.x) - Math.atan2(b.y - SPARK.y, b.x - SPARK.x));
    spurCells.sort((a, b) => a.y - b.y);
    const breaks = [...ring, ...spurCells];

    const cells = [];
    for (const [k, time] of times) {
        const [x, y] = k.split(',').map(Number);
        if (x === SPARK.x && y === SPARK.y) continue;
        cells.push({ x, y, time, el: null, k: -2 });
    }
    const maxT = Math.max(...cells.map((c) => c.time));

    // Sensor pings: hand-placed, but their timestamps come from the BFS —
    // (23,5) sits in the spur's shadow, so its minute is visibly late.
    const pings = [
        { x: 9, y: 4 },
        { x: 23, y: 5 },
        { x: 12, y: 10 },
        { x: 19, y: 10 },
    ]
        .filter((p) => times.has(key(p.x, p.y)))
        .map((p) => ({ ...p, time: times.get(key(p.x, p.y)), el: null, checkEl: null, dotEl: null, labelEl: null, q: -1, checked: null }));

    return { cells, breaks, pings, maxT };
}

const model = buildModel();
const beatP = (P_BURN1 - P_BURN0) / (model.maxT + 0.001);

const sceneG = ref(null);
const sparkG = ref(null);
const displayMinute = ref(0);
const stampOn = ref(false);

const cx = (x) => OX + x * CELL;
const cy = (y) => OY + y * CELL;

/* Map-sheet texture: survey grid, a heavier line every fifth. */
let minorGrid = '';
let majorGrid = '';
for (let i = 0; i <= COLS; i++) {
    const d = `M${cx(i)},0 V424 `;
    if (i % 5 === 0) majorGrid += d;
    else minorGrid += d;
}
for (let j = 0; j <= ROWS; j++) {
    const d = `M0,${cy(j)} H1000 `;
    if (j % 5 === 0) majorGrid += d;
    else minorGrid += d;
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
        const at = P_BRK0 + (i / model.breaks.length) * (P_BRK1 - P_BRK0);
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
            `translate(${cx(SPARK.x) + CELL / 2} ${cy(SPARK.y) + CELL / 2}) scale(${s.toFixed(3)})`,
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
                <pattern id="bh-hatch" width="9" height="9" patternUnits="userSpaceOnUse" patternTransform="rotate(135)">
                    <rect width="9" height="9" fill="#241a12" />
                    <rect width="3" height="9" fill="#6f4c2b" />
                </pattern>
                <radialGradient id="bh-spark" cx="0.5" cy="0.62" r="0.72">
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
                        :x="cx(c.x) + 1.5"
                        :y="cy(c.y) + 1.5"
                        width="25"
                        height="25"
                        rx="2"
                        opacity="0"
                    />
                </g>

                <!-- the reconstructed break line: ring + interior spur -->
                <g>
                    <rect
                        v-for="b in model.breaks"
                        :key="`b${b.x},${b.y}`"
                        :ref="(el) => (b.el = el)"
                        :x="cx(b.x) + 2.5"
                        :y="cy(b.y) + 2.5"
                        width="23"
                        height="23"
                        rx="2"
                        fill="url(#bh-hatch)"
                        stroke="#8a6238"
                        stroke-width="1"
                        opacity="0"
                    />
                </g>

                <!-- the spark -->
                <g ref="sparkG" opacity="0" :transform="`translate(${cx(SPARK.x) + CELL / 2} ${cy(SPARK.y) + CELL / 2})`">
                    <rect x="-12.5" y="-12.5" width="25" height="25" rx="2" fill="url(#bh-spark)" stroke="#c85618" stroke-width="1" />
                    <g transform="translate(-10 -10) scale(0.833)" style="filter: drop-shadow(0 0 5px rgba(255, 122, 45, 0.55))">
                        <path :d="FLAME" fill="#ff7a2d" />
                        <path :d="FLAME" fill="#ffd36b" transform="translate(5.4 8.2) scale(0.55)" />
                    </g>
                </g>

                <!-- the sensor log: pings check off as the front reaches them -->
                <g
                    v-for="pg in model.pings"
                    :key="`p${pg.x},${pg.y}`"
                    :ref="(el) => (pg.el = el)"
                    :transform="`translate(${cx(pg.x) + CELL / 2} ${cy(pg.y) + CELL / 2})`"
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

        <!-- figure captions live in HTML so slice-cropping never cuts them off -->
        <div
            class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3 font-mono text-[10px] tracking-[.12em] text-stock uppercase [text-shadow:0_1px_3px_#000]"
        >
            <div class="flex items-start justify-between">
                <span>Burn reconstruction · replay</span>
                <span class="text-ember-hi">Incident 0341</span>
            </div>
            <div class="flex items-end justify-between text-ash">
                <span>One valid account</span>
                <span>T+{{ String(displayMinute).padStart(2, '0') }} min</span>
            </div>
        </div>

        <div
            class="pointer-events-none absolute right-[7%] bottom-[15%] transition-[opacity,transform] duration-200 ease-out"
            :class="stampOn ? 'scale-100 opacity-95' : 'scale-125 opacity-0'"
        >
            <RubberStamp tone="ember" size="lg" :rotate="-8">Contained</RubberStamp>
        </div>
    </div>
</template>
