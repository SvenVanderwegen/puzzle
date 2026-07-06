<script setup>
/* Animated walkthrough: a fixed 4x5 board, scripted as discrete "beats".
   Every beat re-renders the whole demo state, so pausing, stepping and
   looping are all trivially consistent. Static demo data — no engine
   dependency, so it isn't affected by how the real puzzle is generated. */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';

const R = 4;
const C = 5;
const SPARK = 10;
const BREAKS = [12, 7];
const TIMES = [2, 3, 4, 5, 6, 1, 2, -1, 6, 7, 0, 1, -1, 5, 6, 1, 2, 3, 4, 5];
const MAXT = 7;
const CLUES = { 17: 3, 13: 5 };
const DIRECT = [11, 12];
const XCELL = 12;
const DETOUR = [15, 16, 17, 18, 13];

const CAPS = [
    'Fire starts on the ★ at minute 0 and spreads one cell per minute — up, down, left, right. <span class="bf-stress">Firebreaks stop it dead.</span>',
    'Now read the 5: it sits only <span class="bf-stress">3 steps</span> from the ★, yet it burned at minute 5. Every short route must be blocked.',
    'The fire went around instead — a <span class="bf-stress">5-step route</span>, arriving at minute 5 exactly. The numbers record how the fire really traveled.',
    'In a real puzzle the firebreaks are <span class="bf-stress">hidden</span>. The numbers are your only evidence.',
    'You shade the breaks that make every number exact. Each board has <span class="bf-stress">exactly one answer</span> — and pure deduction finds it.',
];

/* beat: cap, t (wave minute, -1 = unburnt), vb (visible breaks), route/blocked, dur */
const BEATS = [
    { cap: 0, t: 0, vb: 2, dur: 2600 },
    { cap: 0, t: 1, vb: 2, dur: 900 },
    { cap: 0, t: 2, vb: 2, dur: 900 },
    { cap: 0, t: 3, vb: 2, dur: 900 },
    { cap: 0, t: 4, vb: 2, dur: 900 },
    { cap: 0, t: 5, vb: 2, dur: 900 },
    { cap: 0, t: 6, vb: 2, dur: 900 },
    { cap: 0, t: 7, vb: 2, dur: 1600 },
    { cap: 1, t: 7, vb: 2, blocked: true, dur: 4200 },
    { cap: 2, t: 7, vb: 2, route: true, dur: 4600 },
    { cap: 3, t: -1, vb: 0, dur: 3400 },
    { cap: 3, t: -1, vb: 1, dur: 900 },
    { cap: 3, t: -1, vb: 2, dur: 1200 },
    { cap: 4, t: 7, vb: 2, dur: 4800 },
];

const burnBg = TIMES.map((t) => {
    const f = 1 - (0.25 + 0.6 * (t < 0 ? 0 : t / MAXT));
    const mix = (a, c) => Math.round(c + (a - c) * f);
    return `rgb(${mix(255, 255)},${mix(216, 138)},${mix(107, 61)})`;
});

const gridEl = ref(null);
const beatIndex = ref(0);
const playing = ref(false);
const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let timer = null;
let userPaused = false;
let observer = null;

const beat = computed(() => BEATS[beatIndex.value]);
const caption = computed(() => CAPS[beat.value.cap]);
const minuteLabel = computed(() => (beat.value.t < 0 ? '–' : String(Math.min(beat.value.t, MAXT))));
const focused = computed(() => Boolean(beat.value.route || beat.value.blocked));
const buttonLabel = computed(() => (playing.value ? 'Pause' : reducedMotion ? 'Next step' : 'Play'));

function cellState(i) {
    const b = beat.value;
    const isBreak = BREAKS.includes(i);
    const shown = isBreak && BREAKS.indexOf(i) < b.vb;
    const burnt = !isBreak && b.t >= 0 && TIMES[i] <= b.t;
    return {
        isSpark: i === SPARK,
        isClue: CLUES[i] !== undefined,
        isBreak: shown,
        isBurnt: burnt,
        isOk: burnt && CLUES[i] !== undefined,
        isBlocked: Boolean(b.blocked) && DIRECT.includes(i),
        isX: Boolean(b.blocked) && i === XCELL,
        isRoute: Boolean(b.route) && DETOUR.includes(i),
    };
}

function cellClasses(i) {
    const s = cellState(i);
    return {
        'bf-dcell': true,
        'is-spark': s.isSpark,
        'is-clue': s.isClue,
        'is-break': s.isBreak,
        'is-burnt': s.isBurnt,
        'is-ok': s.isOk,
        'is-blocked': s.isBlocked,
        'is-x': s.isX,
        'is-route': s.isRoute,
    };
}

function cellText(i) {
    if (CLUES[i] !== undefined) return String(CLUES[i]);
    const s = cellState(i);
    return s.isBurnt ? String(TIMES[i]) : '';
}

function show(k) {
    beatIndex.value = ((k % BEATS.length) + BEATS.length) % BEATS.length;
}

function tickNext() {
    show(beatIndex.value + 1);
    schedule();
}

function schedule() {
    clearTimeout(timer);
    if (playing.value) timer = setTimeout(tickNext, beat.value.dur);
}

function setPlaying(p) {
    playing.value = p;
    schedule();
}

function toggle() {
    if (reducedMotion) {
        show(beatIndex.value + 1);
        return;
    }
    userPaused = playing.value;
    setPlaying(!playing.value);
}

onMounted(() => {
    show(0);
    if (reducedMotion) {
        setPlaying(false);
    } else {
        setPlaying(true);
        if ('IntersectionObserver' in window && gridEl.value) {
            observer = new IntersectionObserver(
                (entries) => {
                    const visible = entries[0].isIntersecting;
                    if (!visible && playing.value) setPlaying(false);
                    else if (visible && !playing.value && !userPaused) setPlaying(true);
                },
                { threshold: 0.25 },
            );
            observer.observe(gridEl.value);
        }
    }
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    if (observer) observer.disconnect();
});
</script>

<template>
    <div class="bf-demo">
        <p class="min-h-[4.6em] max-w-[58ch] text-[14.5px] leading-normal text-stock" aria-live="polite" v-html="caption"></p>
        <div class="flex flex-wrap items-start gap-[18px]">
            <div ref="gridEl" class="bf-demo-grid" :class="{ 'is-focus': focused }" aria-hidden="true">
                <div
                    v-for="i in R * C"
                    :key="i - 1"
                    :class="cellClasses(i - 1)"
                    :style="{ '--burn-bg': burnBg[i - 1] }"
                >
                    <FlameGlyph v-if="i - 1 === SPARK" glow />
                    <template v-else>{{ cellText(i - 1) }}</template>
                </div>
            </div>
            <div class="flex flex-col items-start gap-2.5">
                <div class="bf-chip">
                    <span class="bf-chip-key">Minute</span>
                    <span class="bf-chip-value">{{ minuteLabel }}</span>
                </div>
                <button type="button" class="bf-btn" @click="toggle">{{ buttonLabel }}</button>
            </div>
        </div>
    </div>
</template>
