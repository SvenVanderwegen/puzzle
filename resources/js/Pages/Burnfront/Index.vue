<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import HowItWorksDemo from './HowItWorksDemo.vue';
import { buildAdj, cellName, fmtClock, validate } from '@/lib/burnfront-engine';

const props = defineProps({
    difficulties: { type: Object, required: true },
    defaultDifficulty: { type: String, required: true },
});

const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const diff = ref(props.defaultDifficulty);
const game = ref(null); /* {n, adj, spark, R, C, N, clueMap, clueIdx, clueVal, clues, difficulty} */
const marks = ref([]); /* 0 none, 1 break, 2 dot */
const cellStyle = ref([]); /* per-cell burn animation style, set on win */
const burnt = ref([]); /* per-cell "burn replay" flag, set on win */
const revealedMinute = ref([]); /* per-cell revealed arrival time text, set on win */

const locked = ref(true);
const hinting = ref(false);
const hintCell = ref(-1);
const boardDone = ref(false);
const veilVisible = ref(false);
const clockText = ref('0:00');
const statusMessage = ref('');
const bannerVisible = ref(false);
const finalTimeText = ref('0:00');

const undoStack = reactive([]);
let marksVersion = 0;
let genToken = 0;
let startAt = 0;
let clockTimer = null;
let statusTimer = null;
let winTimer = null;

const placedCount = computed(() => marks.value.reduce((n, m) => n + (m === 1 ? 1 : 0), 0));
const breakCountText = computed(() => (game.value ? `${placedCount.value}/${game.value.N}` : '0/0'));
const overBudget = computed(() => game.value !== null && placedCount.value > game.value.N);
const undoDisabled = computed(() => locked.value || undoStack.length === 0);
const resetDisabled = computed(() => locked.value);
const hintDisabled = computed(() => locked.value || hinting.value);

function stopClock() {
    if (clockTimer) {
        clearInterval(clockTimer);
        clockTimer = null;
    }
}
function startClock() {
    stopClock();
    startAt = Date.now();
    clockText.value = '0:00';
    clockTimer = setInterval(() => {
        clockText.value = fmtClock(Date.now() - startAt);
    }, 1000);
}

function clearStatus() {
    clearTimeout(statusTimer);
    statusMessage.value = '';
}
function showStatus(msg) {
    statusMessage.value = msg;
    clearTimeout(statusTimer);
    statusTimer = setTimeout(() => {
        statusMessage.value = '';
    }, 3400);
}

function clearHint() {
    hintCell.value = -1;
}

function cellLabel(i) {
    const g = game.value;
    if (i === g.spark) return cellName(i, g.C) + ', the spark';
    if (g.clueMap.has(i)) return cellName(i, g.C) + ', clue: burns at minute ' + g.clueMap.get(i);
    const m = marks.value[i];
    return cellName(i, g.C) + (m === 1 ? ', firebreak' : m === 2 ? ', marked clear' : ', empty');
}

function cellClasses(i) {
    const g = game.value;
    return {
        'bf-cell': true,
        'is-fixed': i === g.spark || g.clueMap.has(i),
        'is-spark': i === g.spark,
        'is-clue': g.clueMap.has(i),
        'is-break': marks.value[i] === 1,
        'is-dot': marks.value[i] === 2,
        'is-hint': hintCell.value === i,
        'is-burnt': burnt.value[i],
    };
}

function cellText(i) {
    const g = game.value;
    if (i === g.spark) return '★';
    if (g.clueMap.has(i)) return g.clueMap.get(i);
    return revealedMinute.value[i] || '';
}

function tap(i, dir) {
    if (locked.value || !game.value) return;
    if (i === game.value.spark || game.value.clueMap.has(i)) return;
    clearHint();
    marksVersion++;
    const prev = marks.value[i];
    marks.value[i] = (prev + dir + 3) % 3;
    undoStack.push([[i, prev]]);
    maybeFinish(prev !== 1 && marks.value[i] === 1);
}

function undo() {
    if (locked.value || !undoStack.length) return;
    clearHint();
    marksVersion++;
    const entry = undoStack.pop();
    for (const [i, prev] of entry) marks.value[i] = prev;
}

function reset() {
    if (locked.value || !game.value) return;
    clearStatus();
    clearHint();
    marksVersion++;
    const entry = [];
    for (let i = 0; i < marks.value.length; i++) {
        if (marks.value[i] !== 0) {
            entry.push([i, marks.value[i]]);
            marks.value[i] = 0;
        }
    }
    if (entry.length) undoStack.push(entry);
}

function maybeFinish(justCompleted) {
    const g = game.value;
    if (placedCount.value !== g.N) return;
    const breaks = new Uint8Array(marks.value.length);
    for (let i = 0; i < marks.value.length; i++) breaks[i] = marks.value[i] === 1 ? 1 : 0;
    const d = validate(g.n, g.adj, g.spark, g.clueIdx, g.clueVal, g.N, breaks);
    if (d) {
        win(d);
    } else if (justCompleted) {
        showStatus(`All ${g.N} breaks are down, but the fire disagrees with the report. Something’s off.`);
    }
}

function win(times) {
    const g = game.value;
    locked.value = true;
    clearStatus();
    clearHint();
    stopClock();
    boardDone.value = true;
    let maxT = 0;
    for (let i = 0; i < times.length; i++) if (times[i] > maxT) maxT = times[i];
    const step = reducedMotion ? 0 : Math.min(140, 1400 / Math.max(1, maxT));
    for (let i = 0; i < times.length; i++) {
        if (times[i] < 0) continue; /* firebreaks stay dark */
        const t = times[i];
        const warm = maxT ? t / maxT : 0; /* flame core early, deep ember late */
        const mix = (a, c, f) => Math.round(a + (c - a) * f);
        const f = 0.25 + 0.6 * warm; /* 0 -> mostly flame, 1 -> mostly ember */
        cellStyle.value[i] = {
            '--burn-bg': `rgb(${mix(255, 255, f)},${mix(216, 138, f)},${mix(107, 61, f)})`,
            transitionDelay: t * step + 'ms',
        };
        if (!g.clueMap.has(i) && i !== g.spark) revealedMinute.value[i] = String(t);
        burnt.value[i] = true;
    }
    const total = maxT * step + 500;
    const token = genToken;
    clearTimeout(winTimer);
    winTimer = setTimeout(() => {
        if (token !== genToken) return;
        for (let i = 0; i < cellStyle.value.length; i++) {
            if (cellStyle.value[i]) cellStyle.value[i] = { ...cellStyle.value[i], transitionDelay: '0ms' };
        }
        finalTimeText.value = fmtClock(Date.now() - startAt);
        bannerVisible.value = true;
    }, total);
}

async function newGame() {
    const token = ++genToken;
    locked.value = true;
    stopClock();
    clearStatus();
    veilVisible.value = true;
    bannerVisible.value = false;
    try {
        const resp = await fetch('/puzzle?difficulty=' + encodeURIComponent(diff.value));
        if (!resp.ok) throw new Error('puzzle request failed');
        const p = await resp.json();
        if (token !== genToken) return; /* superseded by a newer request */
        const n = p.rows * p.cols;
        game.value = reactive({
            n,
            adj: buildAdj(p.rows, p.cols),
            spark: p.spark,
            R: p.rows,
            C: p.cols,
            N: p.breaks,
            clueMap: new Map(p.clues),
            clueIdx: p.clues.map((cv) => cv[0]),
            clueVal: p.clues.map((cv) => cv[1]),
            clues: p.clues,
            difficulty: diff.value,
        });
        marks.value = new Array(n).fill(0);
        cellStyle.value = new Array(n).fill(null);
        burnt.value = new Array(n).fill(false);
        revealedMinute.value = new Array(n).fill('');
        undoStack.length = 0;
        boardDone.value = false;
        clearHint();
        locked.value = false;
        startClock();
    } catch (e) {
        if (token === genToken) {
            /* Restore whatever board was already up rather than leaving it
               locked forever: tap/undo/reset all bail out while locked. */
            locked.value = false;
        }
        showStatus("Couldn't reach the incident desk. Try again.");
    } finally {
        if (token === genToken) veilVisible.value = false;
    }
}

/* Asks the incident desk for one forced deduction given the clues, plus
   whichever breaks and clear-ground dots are already placed — never the
   full solution. Purely server-side: the client has no deduction solver,
   only the local validator used at completion. Both the puzzle token and
   a marks version are captured before the request goes out and rechecked
   after, so a reply for a superseded puzzle or a stale board (the player
   tapped, undid, reset, or started a new fire while waiting) is dropped
   instead of being painted onto whatever's on screen now. */
async function requestHint() {
    if (locked.value || !game.value || hinting.value) return;
    hinting.value = true;
    const token = genToken;
    const version = marksVersion;
    try {
        const shaded = [];
        const open = [];
        for (let i = 0; i < marks.value.length; i++) {
            if (marks.value[i] === 1) shaded.push(i);
            else if (marks.value[i] === 2) open.push(i);
        }
        const qs = new URLSearchParams({
            difficulty: game.value.difficulty,
            spark: String(game.value.spark),
            clues: JSON.stringify(game.value.clues),
            shaded: JSON.stringify(shaded),
            open: JSON.stringify(open),
        });
        const resp = await fetch('/hint?' + qs.toString());
        if (!resp.ok) throw new Error('hint request failed');
        const data = await resp.json();
        if (token !== genToken || version !== marksVersion) return; /* superseded */
        clearHint();
        if (data.status === 'forced') {
            hintCell.value = data.cell;
            showStatus(cellName(data.cell, game.value.C) + ' has to ' + (data.state === 'break' ? 'be a firebreak.' : 'stay clear.'));
        } else if (data.status === 'contradiction') {
            showStatus('Something already marked doesn’t fit the report. Recheck your breaks.');
        } else if (data.status === 'complete') {
            showStatus('Every cell is already accounted for.');
        } else {
            showStatus("No forced move right now — take another look at what's placed.");
        }
    } catch (e) {
        if (token === genToken && version === marksVersion) showStatus("Couldn't reach the incident desk. Try again.");
    } finally {
        hinting.value = false;
    }
}

function selectDifficulty(key) {
    if (key === diff.value) return;
    diff.value = key;
    newGame();
}

onBeforeUnmount(() => {
    stopClock();
    clearTimeout(statusTimer);
    clearTimeout(winTimer);
});

newGame();
</script>

<template>
    <Head title="Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-10 pb-16">
        <header class="flex flex-col gap-2">
            <p class="text-[11px] tracking-[.22em] text-ash-dim uppercase">Incident report &middot; deduction puzzle</p>
            <h1 class="font-staatliches text-[clamp(52px,11vw,76px)] leading-[0.95] font-normal tracking-[.035em] text-paper text-balance">
                BURNFRONT<span class="text-flame" style="text-shadow: 0 0 18px rgba(255, 216, 107, 0.45)">★</span>
            </h1>
            <p class="mt-0.5 max-w-[46ch] text-ash">
                The fire is out. The report says when it reached each numbered cell. Reconstruct the firebreaks that shaped its
                path — there is exactly one way, and pure logic finds it.
            </p>
        </header>

        <section class="relative" aria-label="Puzzle board">
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="bf-seg" role="group" aria-label="Difficulty">
                    <button
                        v-for="(config, key) in difficulties"
                        :key="key"
                        type="button"
                        class="bf-seg-btn"
                        :class="{ 'is-active': key === diff }"
                        @click="selectDifficulty(key)"
                    >
                        {{ config.label }}
                    </button>
                </div>
                <button type="button" class="bf-btn bf-btn-primary" @click="newGame">New fire</button>
                <button type="button" class="bf-btn" :disabled="hintDisabled" @click="requestHint">Hint</button>
                <button type="button" class="bf-btn" :disabled="undoDisabled" @click="undo">Undo</button>
                <button type="button" class="bf-btn" :disabled="resetDisabled" @click="reset">Reset</button>
                <div class="ml-auto flex gap-2.5">
                    <div class="bf-chip" :class="{ 'is-over': overBudget }">
                        <span class="bf-chip-key">Breaks</span>
                        <span class="bf-chip-value">{{ breakCountText }}</span>
                    </div>
                    <div class="bf-chip">
                        <span class="bf-chip-key">Time</span>
                        <span class="bf-chip-value">{{ clockText }}</span>
                    </div>
                </div>
            </div>

            <div class="relative mt-3.5">
                <div
                    v-if="game"
                    class="bf-board-grid"
                    :class="{ 'is-done': boardDone }"
                    :style="{ '--cols': game.C, gridTemplateColumns: `repeat(${game.C},1fr)` }"
                    aria-label="Burnfront grid"
                >
                    <button
                        v-for="i in game.n"
                        :key="i - 1"
                        type="button"
                        :class="cellClasses(i - 1)"
                        :style="cellStyle[i - 1] || {}"
                        :aria-label="cellLabel(i - 1)"
                        @click="tap(i - 1, 1)"
                        @contextmenu.prevent="tap(i - 1, -1)"
                    >
                        <span class="bf-cell-minute">{{ cellText(i - 1) }}</span>
                    </button>
                </div>
                <div class="bf-veil" :class="{ 'is-visible': veilVisible }">
                    <span>Surveying terrain&hellip;</span>
                </div>
            </div>

            <div class="mt-3.5 flex min-h-11 items-baseline gap-3">
                <div v-if="bannerVisible" class="flex flex-col gap-1.5">
                    <span class="bf-banner-headline">FIRE MAPPED</span>
                    <p class="text-sm text-ash">
                        Contained in <span class="tabular-nums text-paper">{{ finalTimeText }}</span> — every clue burns on
                        time.
                    </p>
                </div>
                <p v-else-if="statusMessage" class="bf-status" role="status">{{ statusMessage }}</p>
                <p v-else class="max-w-[60ch] text-[13px] text-ash-dim">
                    Tap a cell to dig a firebreak &middot; tap again for a clear-ground dot &middot; a third tap erases. New
                    here? The walkthrough below shows exactly how the fire moves.
                </p>
            </div>
        </section>

        <section class="flex flex-col gap-4" aria-label="How Burnfront works">
            <h2 class="font-staatliches text-[28px] font-normal tracking-[.06em] text-paper">HOW IT WORKS</h2>
            <HowItWorksDemo />
            <div class="bf-rulecols">
                <div class="flex flex-col gap-2.5 bf-rulecol">
                    <h3>The rules</h3>
                    <ol>
                        <li><strong>Shade exactly N firebreaks.</strong> The counter above the board sets N. The ★ and the numbered cells are never breaks.</li>
                        <li><strong>Fire spreads one cell per minute.</strong> It starts on the ★ at minute 0 and moves up, down, left and right — never diagonally, never through a break.</li>
                        <li><strong>Everything else burns.</strong> Every cell that isn&rsquo;t a firebreak must be reached by the fire eventually. No safe pockets.</li>
                        <li><strong>Numbers are exact arrival times.</strong> A cell marked 5 caught fire at minute 5 — not before, not after.</li>
                    </ol>
                </div>
                <div class="flex flex-col gap-2.5 bf-rulecol">
                    <h3>Reading the numbers</h3>
                    <ul>
                        <li>A cell&rsquo;s minute is the length of the fire&rsquo;s <strong>shortest open route</strong> from the ★ — never less than the straight-line distance.</li>
                        <li><strong>Bigger than the distance? Something is in the way.</strong> A 5 sitting 3 steps from the ★ proves every shorter route is blocked. That is how numbers reveal breaks.</li>
                        <li>Neighboring burnt cells differ by at most 1, and a cell burning at minute t caught it from a neighbor that burned at t&minus;1 — wavefronts, not teleports.</li>
                        <li><strong>Every break earns its place:</strong> open it, and the fire would reach some number too early. None hides in a corner justified by counting alone.</li>
                        <li>Tap cycles firebreak &rarr; dot &rarr; empty. The dot is your own note for &ldquo;proven open&rdquo; — it isn&rsquo;t checked. The moment your Nth break lands, the board checks itself.</li>
                    </ul>
                </div>
            </div>
        </section>

        <footer>
            <p class="max-w-[58ch] text-[12.5px] text-ash-dim">
                Every fire is generated on the Burnfront incident desk and machine-verified: exactly one valid placement of
                breaks, a solving path that needs no guessing, and no firebreak the clues can&rsquo;t justify.
            </p>
        </footer>
    </main>
</template>
