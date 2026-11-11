<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { buildAdj, cellName, fmtClock, validate } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';
import { heatCell } from '@/lib/heat';
import { t } from '@/lib/i18n';
import { plural, tOr } from '@/lib/plural';
import { isDark } from '@/lib/theme';

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
    if (!move || typeof move !== 'object') return t('replay.move.unknown');
    const where = inRange(move.cell) ? cellNameOf(move.cell) : '?';
    switch (move.type) {
        case 'mark':
            if (move.value === 1) return t('replay.move.break', { where });
            if (move.value === 2) return t('replay.move.dot', { where });
            return t('replay.move.clear', { where });
        case 'hint':
            return t('replay.move.hint', { where });
        case 'undo':
            return plural('replay.move.undo', Array.isArray(move.restores) ? move.restores.length : 0);
        case 'reset':
            return plural('replay.move.reset', Array.isArray(move.cleared) ? move.cleared.length : 0);
        default:
            return t('replay.move.unknown');
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

// Colour = time, same heat scale as the board: the star and the numbers are
// always coloured, every other square once the replay reaches the solved board.
const lastMinute = computed(() => {
    let m = Math.max(1, ...clueVal);
    for (const v of burnTimes.value ?? []) if (v > m) m = v;
    return m;
});

function minuteOf(i) {
    if (i === props.play.spark) return 0;
    if (clueMap.has(i)) return clueMap.get(i);
    const tm = burnTimes.value?.[i];
    return tm != null && tm >= 0 ? tm : null;
}

const cellStyles = computed(() =>
    Array.from({ length: n }, (_, i) => {
        const m = minuteOf(i);
        if (m === null) return null;
        const c = heatCell(m, lastMinute.value, isDark.value);
        return { '--heat-bg': c.bg, '--heat-fg': c.fg };
    })
);

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
        'is-burnt': !!burnTimes.value && (burnTimes.value[i] ?? -1) >= 0 && i !== props.play.spark && !clueMap.has(i),
    };
}

function cellText(i) {
    if (clueMap.has(i)) return clueMap.get(i);
    if (burnTimes.value && i !== props.play.spark) {
        const m = burnTimes.value[i];
        return m >= 0 ? String(m) : '';
    }
    return '';
}

function cellLabel(i) {
    const where = cellNameOf(i);
    if (i === props.play.spark) return t('play.cell.spark', { where });
    if (clueMap.has(i)) return t('play.cell.clue', { where, minute: clueMap.get(i) });
    const m = board.value.marks[i];
    if (m === 1) return t('play.cell.break', { where });
    if (m === 2) return t('play.cell.dot', { where });
    const bt = burnTimes.value?.[i];
    if (bt != null && bt >= 0) return t('play.cell.burnt', { where, minute: bt });
    return t('play.cell.empty', { where });
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

const title =
    props.play.mode === 'daily'
        ? t('replay.daily')
        : props.play.difficulty
          ? tOr(`common.difficulty.${props.play.difficulty}`, props.play.difficultyLabel || t('replay.practice'))
          : t('replay.practice');
const boardSubline = t('replay.meta', { rows: props.play.rows, cols: props.play.cols, breaks: props.play.breaks });
const currentMoveTimeText = computed(() => {
    if (!hasMoves || stepIndex.value === 0) return '0:00';
    return fmtClock(moves[stepIndex.value - 1]?.t ?? 0);
});
</script>

<template>
    <Head :title="t('replay.pageTitle')" />

    <div class="bf-page is-narrow bf-play">
        <SiteBar :back="{ href: '/game/replays', text: t('replay.back') }" :crumb="t('replay.eyebrow')" />

        <main class="flex flex-col gap-3">
            <header class="flex flex-col gap-1 pt-1">
                <p class="bf-eyebrow">{{ t('replay.eyebrow') }}</p>
                <h1 class="bf-page-title text-[30px]">{{ title }}</h1>
                <p class="bf-row-sub m-0">{{ boardSubline }}</p>
            </header>

            <div class="flex gap-2">
                <div class="bf-chip">
                    <span class="bf-chip-key">{{ t('replay.time') }}</span>
                    <span class="bf-chip-value">{{ play.timeMs != null ? fmtClock(play.timeMs) : '—' }}</span>
                </div>
                <div class="bf-chip">
                    <span class="bf-chip-key">{{ t('replay.hints') }}</span>
                    <span class="bf-chip-value">{{ play.hintsUsed }}</span>
                </div>
                <div class="bf-chip">
                    <span class="bf-chip-key">{{ t('replay.moves') }}</span>
                    <span class="bf-chip-value">{{ hasMoves ? `${stepIndex}/${moves.length}` : '—' }}</span>
                </div>
            </div>

            <div class="bf-board-wrap">
                <div
                    class="bf-board-grid"
                    :class="{ 'is-done': isComplete }"
                    :style="{ '--cols': play.cols, gridTemplateColumns: `repeat(${play.cols}, minmax(0, 1fr))` }"
                    role="group"
                    :aria-label="t('replay.gridLabel', { rows: play.rows, cols: play.cols })"
                >
                    <div
                        v-for="i in n"
                        :key="i - 1"
                        :class="cellClasses(i - 1)"
                        :style="cellStyles[i - 1] || {}"
                        role="img"
                        :aria-label="cellLabel(i - 1)"
                    >
                        <span class="bf-cell-minute">
                            <FlameGlyph v-if="i - 1 === play.spark" />
                            <template v-else>{{ cellText(i - 1) }}</template>
                        </span>
                    </div>
                </div>
            </div>

            <p class="bf-message" aria-live="polite">
                <template v-if="!hasMoves">{{ t('replay.noMoves') }}</template>
                <template v-else-if="stepIndex === 0">{{ t('replay.atStart') }}</template>
                <template v-else>{{
                    t('replay.step', { step: stepIndex, total: moves.length, time: currentMoveTimeText, what: describeMove(moves[stepIndex - 1]) })
                }}</template>
            </p>

            <div v-if="hasMoves" class="flex flex-col gap-2.5">
                <input
                    type="range"
                    class="bf-replay-range w-full"
                    min="0"
                    :max="moves.length"
                    :value="stepIndex"
                    :aria-label="t('replay.scrub')"
                    @input="onScrub"
                />
                <div class="grid grid-cols-[48px_48px_minmax(0,1fr)_48px_48px] gap-2">
                    <button type="button" class="bf-btn bf-btn-soft px-0" :title="t('replay.restart')" :aria-label="t('replay.restart')" :disabled="atStart" @click="restart">
                        <span aria-hidden="true">&laquo;</span>
                    </button>
                    <button type="button" class="bf-btn bf-btn-soft px-0" :title="t('replay.stepBack')" :aria-label="t('replay.stepBack')" :disabled="atStart" @click="stepBack">
                        <span aria-hidden="true">&lsaquo;</span>
                    </button>
                    <button type="button" class="bf-btn bf-btn-primary min-w-0 px-2" @click="togglePlay">
                        {{ playing ? t('replay.pause') : atEnd ? t('replay.again') : t('replay.play') }}
                    </button>
                    <button type="button" class="bf-btn bf-btn-soft px-0" :title="t('replay.stepForward')" :aria-label="t('replay.stepForward')" :disabled="atEnd" @click="stepForward">
                        <span aria-hidden="true">&rsaquo;</span>
                    </button>
                    <button type="button" class="bf-btn bf-btn-soft px-0" :title="t('replay.toEnd')" :aria-label="t('replay.toEnd')" :disabled="atEnd" @click="jumpToEnd">
                        <span aria-hidden="true">&raquo;</span>
                    </button>
                </div>
                <button type="button" class="bf-btn bf-btn-soft" :aria-label="t('replay.speedLabel', { n: SPEEDS[speedTier] })" @click="cycleSpeed">
                    {{ t('replay.speed', { n: SPEEDS[speedTier] }) }}
                </button>
            </div>
        </main>
    </div>
</template>
