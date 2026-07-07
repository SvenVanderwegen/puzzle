<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { buildAdj, cellName, COLS, fmtClock, validate } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';

const props = defineProps({
    play: { type: Object, required: true }, // {id, mode, difficulty, difficultyLabel, date, rows, cols, breaks, spark, clues, shadedCells, moves, timeMs, hintsUsed, moveCount, playedAt}
});

const SPEEDS = [1, 2, 4, 8, 16]; // moves applied per second while playing

const n = props.play.rows * props.play.cols;
const adj = buildAdj(props.play.rows, props.play.cols);
const clueMap = new Map(props.play.clues);
const clueIdx = props.play.clues.map((cv) => cv[0]);
const clueVal = props.play.clues.map((cv) => cv[1]);
const moves = Array.isArray(props.play.moves) ? props.play.moves : [];

// Moves are unverified client-reported telemetry (see GamePlay's class doc
// and BurnfrontController::normalizeMoves()) — never validated beyond a
// size cap, so a malformed or out-of-range entry must be skipped rather
// than corrupt the reconstruction or throw.
function inRange(i) {
    return Number.isInteger(i) && i >= 0 && i < n;
}

function applyMove(marks, hintSafe, move) {
    if (!move || typeof move !== 'object') return;
    switch (move.type) {
        case 'mark':
        case 'hint':
            if (inRange(move.cell) && Number.isInteger(move.value)) {
                marks[move.cell] = move.value;
                hintSafe[move.cell] = move.type === 'hint';
            }
            break;
        case 'undo':
            for (const entry of Array.isArray(move.restores) ? move.restores : []) {
                const [i, prev, prevHintSafe] = Array.isArray(entry) ? entry : [];
                if (inRange(i) && Number.isInteger(prev)) {
                    marks[i] = prev;
                    hintSafe[i] = !!prevHintSafe;
                }
            }
            break;
        case 'reset':
            for (const entry of Array.isArray(move.cleared) ? move.cleared : []) {
                const i = Array.isArray(entry) ? entry[0] : undefined;
                if (inRange(i)) {
                    marks[i] = 0;
                    hintSafe[i] = false;
                }
            }
            break;
    }
}

// Reconstructs board state by replaying moves[0..index) from a blank board —
// the move log is append-only and was never popped during play (see
// Play.vue's moveLog), so this is a pure fold, not a stack to unwind.
function replayTo(index) {
    const marks = new Array(n).fill(0);
    const hintSafe = new Array(n).fill(false);
    for (let k = 0; k < index; k++) applyMove(marks, hintSafe, moves[k]);
    return { marks, hintSafe };
}

function cellNameOf(i) {
    return cellName(i, props.play.cols);
}

function describeMove(move) {
    if (!move || typeof move !== 'object') return 'Unrecorded action';
    switch (move.type) {
        case 'mark':
            if (move.value === 1) return `Firebreak dug at ${inRange(move.cell) ? cellNameOf(move.cell) : '?'}`;
            if (move.value === 2) return `Marked ${inRange(move.cell) ? cellNameOf(move.cell) : '?'} clear`;
            return `Cleared ${inRange(move.cell) ? cellNameOf(move.cell) : '?'}`;
        case 'hint':
            return `Hint: forced firebreak at ${inRange(move.cell) ? cellNameOf(move.cell) : '?'}`;
        case 'undo': {
            const count = Array.isArray(move.restores) ? move.restores.length : 0;
            return `Undo (${count} cell${count === 1 ? '' : 's'})`;
        }
        case 'reset': {
            const count = Array.isArray(move.cleared) ? move.cleared.length : 0;
            return `Reset (${count} cell${count === 1 ? '' : 's'})`;
        }
        default:
            return 'Unrecorded action';
    }
}

// No move log at all (a game filed before this feature shipped, or one
// whose log was fully dropped by normalizeMoves()'s size cap) — there's
// nothing to step through, so the board just shows its known final state.
const hasMoves = moves.length > 0;

const stepIndex = ref(0); // 0..moves.length — number of moves applied so far
const playing = ref(false);
const speedTier = ref(2); // index into SPEEDS
let timer = null;

const atStart = computed(() => stepIndex.value === 0);
const atEnd = computed(() => stepIndex.value === moves.length);
const isComplete = computed(() => !hasMoves || atEnd.value);

// The final frame always reads straight off shadedCells — the actual board
// the server independently verified at solve time — rather than trusting
// the replayed move log to land on it exactly. moves is unverified client
// telemetry capped in size (see normalizeMoves()); an oversized run's log
// can be truncated before it reaches the winning move, so re-deriving the
// end state purely from replay could show a board that never actually
// solved anything. Mid-replay there's no such guarantee to fall back on, so
// those frames are the replayed reconstruction, same as it looked live.
const finalMarks = computed(() => {
    const marks = new Array(n).fill(0);
    for (const cell of props.play.shadedCells ?? []) {
        if (inRange(cell)) marks[cell] = 1;
    }
    return marks;
});

const board = computed(() => {
    if (!hasMoves || atEnd.value) {
        return { marks: finalMarks.value, hintSafe: new Array(n).fill(false) };
    }
    return replayTo(stepIndex.value);
});

// Burn colors only ever paint once the board reads as the completed, known-
// solved board — mid-replay the board just shows plain marks, same as an
// in-progress game.
const burnTimes = computed(() => {
    if (!isComplete.value) return null;
    const breaks = new Uint8Array(n);
    for (let i = 0; i < n; i++) breaks[i] = board.value.marks[i] === 1 ? 1 : 0;
    const d = validate(n, adj, props.play.spark, clueIdx, clueVal, props.play.breaks, breaks);
    return d ? Array.from(d) : null;
});

function burnStyle(times) {
    let maxT = 0;
    for (const t of times) if (t > maxT) maxT = t;
    return times.map((t) => {
        if (t < 0) return null;
        const warm = maxT ? t / maxT : 0;
        const mix = (a, c, f) => Math.round(a + (c - a) * f);
        const f = 0.25 + 0.6 * warm;
        return { '--burn-bg': `rgb(${mix(255, 255, f)},${mix(216, 138, f)},${mix(107, 61, f)})` };
    });
}

const cellStyles = computed(() => (burnTimes.value ? burnStyle(burnTimes.value) : null));

function cellClasses(i) {
    const isBreak = board.value.marks[i] === 1;
    return {
        'bf-cell': true,
        'is-fixed': true, // every cell is read-only here — reused just to kill the pointer cursor/hover styling Play.vue's clickable board needs
        'is-spark': i === props.play.spark,
        'is-clue': clueMap.has(i),
        'is-break': isBreak,
        'is-dot': board.value.marks[i] === 2,
        'is-hint-safe': isBreak && !!board.value.hintSafe[i],
        'is-burnt': !!cellStyles.value?.[i],
    };
}

function cellText(i) {
    if (clueMap.has(i)) return clueMap.get(i);
    if (cellStyles.value && burnTimes.value && !clueMap.has(i) && i !== props.play.spark) {
        const t = burnTimes.value[i];
        return t >= 0 ? String(t) : '';
    }
    return '';
}

function cellLabel(i) {
    if (i === props.play.spark) return cellNameOf(i) + ', the spark';
    if (clueMap.has(i)) return cellNameOf(i) + ', clue: burns at minute ' + clueMap.get(i);
    const m = board.value.marks[i];
    if (m === 1) return cellNameOf(i) + ', firebreak';
    return cellNameOf(i) + (m === 2 ? ', marked clear' : ', empty');
}

function stopPlaying() {
    playing.value = false;
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

function stepForward() {
    if (stepIndex.value < moves.length) stepIndex.value++;
    if (stepIndex.value >= moves.length) stopPlaying();
}

function stepBack() {
    stopPlaying();
    if (stepIndex.value > 0) stepIndex.value--;
}

function restart() {
    stopPlaying();
    stepIndex.value = 0;
}

function jumpToEnd() {
    stopPlaying();
    stepIndex.value = moves.length;
}

function togglePlay() {
    if (!hasMoves) return;
    if (playing.value) {
        stopPlaying();
        return;
    }
    if (atEnd.value) stepIndex.value = 0;
    playing.value = true;
    timer = setInterval(stepForward, 1000 / SPEEDS[speedTier.value]);
}

function cycleSpeed() {
    speedTier.value = (speedTier.value + 1) % SPEEDS.length;
    if (playing.value) {
        clearInterval(timer);
        timer = setInterval(stepForward, 1000 / SPEEDS[speedTier.value]);
    }
}

function onScrub(e) {
    stopPlaying();
    stepIndex.value = Number(e.target.value);
}

function onGlobalKeydown(e) {
    if (!hasMoves || e.ctrlKey || e.metaKey || e.altKey) return;
    const tag = document.activeElement?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    switch (e.key) {
        case ' ':
            e.preventDefault();
            togglePlay();
            break;
        case 'ArrowRight':
            e.preventDefault();
            stopPlaying();
            stepForward();
            break;
        case 'ArrowLeft':
            e.preventDefault();
            stepBack();
            break;
        case 'Home':
            e.preventDefault();
            restart();
            break;
        case 'End':
            e.preventDefault();
            jumpToEnd();
            break;
    }
}

onMounted(() => window.addEventListener('keydown', onGlobalKeydown));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onGlobalKeydown);
    stopPlaying();
});

const title = props.play.mode === 'daily' ? 'Daily Incident' : props.play.difficultyLabel || 'Endless';
const boardSubline = `${props.play.rows}×${props.play.cols} · ${props.play.breaks} breaks`;
const currentMoveTimeText = computed(() => {
    if (!hasMoves || stepIndex.value === 0) return '0:00';
    return fmtClock(moves[stepIndex.value - 1]?.t ?? 0);
});
</script>

<template>
    <Head title="Case Replay — Burnfront" />

    <main class="mx-auto flex min-h-dvh max-w-[640px] flex-col gap-2.5 px-4 pt-3 pb-4">
        <SiteBar :back="{ href: '/game/replays', text: 'Replays' }" crumb="Case replay" />

        <div class="mt-1 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate font-staatliches text-[28px] leading-none tracking-[.02em] text-stock">{{ title }}</h2>
                <p class="mt-1 font-mono text-[10px] tracking-[.1em] text-ash-dim uppercase">{{ boardSubline }}</p>
            </div>
        </div>

        <div class="mt-2.5 flex gap-2">
            <div class="bf-chip">
                <span class="bf-chip-key">Time</span>
                <span class="bf-chip-value">{{ play.timeMs != null ? fmtClock(play.timeMs) : '—' }}</span>
            </div>
            <div class="bf-chip">
                <span class="bf-chip-key">Hints</span>
                <span class="bf-chip-value">{{ play.hintsUsed }}</span>
            </div>
            <div class="bf-chip">
                <span class="bf-chip-key">Moves</span>
                <span class="bf-chip-value">{{ hasMoves ? `${stepIndex}/${moves.length}` : '—' }}</span>
            </div>
        </div>

        <div class="relative mt-2.5 flex min-h-0 flex-1 items-center justify-center">
            <div class="bf-plotsheet">
                <div class="bf-plotsheet-grid">
                    <div></div>
                    <div class="bf-rail-cols" :style="{ gridTemplateColumns: `repeat(${play.cols},1fr)` }">
                        <span v-for="c in play.cols" :key="`col${c}`" class="bf-rail-label">{{ COLS[c - 1] }}</span>
                    </div>
                    <div class="bf-rail-rows" :style="{ gridTemplateRows: `repeat(${play.rows},1fr)` }">
                        <span v-for="r in play.rows" :key="`row${r}`" class="bf-rail-label">{{ r }}</span>
                    </div>
                    <div
                        class="bf-board-grid is-done"
                        :style="{ '--cols': play.cols, gridTemplateColumns: `repeat(${play.cols},1fr)` }"
                        aria-label="Burnfront grid, read-only replay"
                    >
                        <div
                            v-for="i in n"
                            :key="i - 1"
                            :class="cellClasses(i - 1)"
                            :style="cellStyles?.[i - 1] || {}"
                            :aria-label="cellLabel(i - 1)"
                        >
                            <span class="bf-cell-minute">
                                <FlameGlyph v-if="i - 1 === play.spark" glow />
                                <template v-else>{{ cellText(i - 1) }}</template>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-2.5 flex min-h-9 flex-col gap-1.5">
            <p v-if="!hasMoves" class="max-w-[60ch] text-[13px] text-ash-dim">
                No move-by-move detail was recorded for this run — showing the final board only.
            </p>
            <p v-else class="max-w-[60ch] text-[13px] text-ash-dim">
                <template v-if="stepIndex === 0">Start of run &middot; nothing placed yet.</template>
                <template v-else>Move {{ stepIndex }} of {{ moves.length }} &middot; {{ currentMoveTimeText }} &middot; {{ describeMove(moves[stepIndex - 1]) }}</template>
            </p>
        </div>

        <div v-if="hasMoves" class="mt-1 flex flex-col gap-2.5">
            <input
                type="range"
                class="w-full accent-ember"
                min="0"
                :max="moves.length"
                :value="stepIndex"
                @input="onScrub"
                aria-label="Scrub through recorded moves"
            />
            <div class="flex gap-2">
                <button type="button" class="bf-btn" title="Restart (Home)" :disabled="atStart" @click="restart">&laquo;</button>
                <button type="button" class="bf-btn" title="Step back (Left arrow)" :disabled="atStart" @click="stepBack">&lsaquo;</button>
                <button type="button" class="bf-btn bf-btn-primary flex-1" title="Play/Pause (Space)" @click="togglePlay">
                    {{ playing ? 'Pause' : atEnd ? 'Replay' : 'Play' }}
                </button>
                <button type="button" class="bf-btn" title="Step forward (Right arrow)" :disabled="atEnd" @click="stepForward">&rsaquo;</button>
                <button type="button" class="bf-btn" title="Jump to end (End)" :disabled="atEnd" @click="jumpToEnd">&raquo;</button>
                <button type="button" class="bf-btn" title="Playback speed" @click="cycleSpeed">{{ SPEEDS[speedTier] }}&times;</button>
            </div>
        </div>
    </main>
</template>
