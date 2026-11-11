<script setup>
/* The first-time lesson (/learn): three short steps on a 4x4 board, built
   for a player who has never seen the game. No clock, no account, one tap
   places or removes a firebreak, and every message stays on screen until the
   next move. Every block has a fixed height so nothing jumps when a move is
   made. The logic lives in lib/burnfront-lesson.js. */
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { t } from '@/lib/i18n';
import { heatCell, INK } from '@/lib/heat';
import { completeOnboarding } from '@/lib/burnfront-onboarding';
import { play } from '@/lib/sound';
import {
    LESSON,
    advance,
    burn,
    countBreaks,
    initialState,
    puzzleMessage,
    squareName,
    stepOf,
    tapResult,
} from '@/lib/burnfront-lesson';

const FIRST_FIRE_HREF = '/endless/play?difficulty=lookout';

const reducedMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const state = ref(initialState());
/* When the current burn replay started (0 = no replay yet in this phase),
   and a clock ref the ticker bumps so the replay redraws minute by minute. */
const replayAt = ref(0);
const now = ref(0);
const isDark = ref(false);
let timer = null;

function stepMs(phase) {
    return phase >= 4 ? 130 : 280;
}

function setState(next) {
    const phaseChanged = next.phase !== state.value.phase;
    state.value = next;
    if (next.replay) {
        replayAt.value = Date.now();
        now.value = replayAt.value;
        tick();
    } else if (phaseChanged) {
        replayAt.value = 0;
    }
    if (next.phase === 5) completeOnboarding();
}

function tick() {
    if (timer) return;
    timer = setInterval(() => {
        now.value = Date.now();
        if (minutesShown.value > LESSON.maxMinute) {
            clearInterval(timer);
            timer = null;
        }
    }, 65);
}

/* How many minutes of the current replay are on screen; -1 before any replay. */
const minutesShown = computed(() => {
    if (!replayAt.value) return -1;
    if (reducedMotion) return Infinity;
    return Math.floor((now.value - replayAt.value) / stepMs(state.value.phase));
});

function onTap(i) {
    const next = tapResult(state.value, i);
    if (!next) return;
    if (next.full) play('wrong');
    else if (next.phase === 5) play('solved');
    else play(next.marks[i] === 1 ? 'place' : 'clear');
    setState(next);
}

function onAdvance() {
    setState(advance(state.value));
}

function restart() {
    setState(initialState());
}

function leave() {
    completeOnboarding();
}

const dist = computed(() => burn(state.value.marks));
const placed = computed(() => countBreaks(state.value.marks));
const showClues = computed(() => state.value.phase >= 4);

const cells = computed(() => {
    const { phase, marks } = state.value;
    const shown = minutesShown.value;
    return marks.map((mark, i) => {
        const where = squareName(i);
        const d = dist.value[i];
        const burnt = d >= 0 && shown >= 0 && d <= shown;
        const clue = LESSON.clues[i];
        const cell = { i, label: '', kind: 'empty', bg: null, fg: INK, badge: null, dashed: false, aria: '' };
        if (i === LESSON.spark) {
            Object.assign(cell, heatCell(0, LESSON.maxMinute, isDark.value), { kind: 'spark' });
            cell.aria = t('lesson.cell.spark', { where });
        } else if (mark === 1) {
            cell.kind = 'break';
            cell.aria = t('lesson.cell.firebreak', { where });
        } else if (showClues.value && clue != null) {
            Object.assign(cell, heatCell(clue, LESSON.maxMinute, isDark.value), { kind: 'clue', label: String(clue) });
            if (shown >= 0 && (burnt || d < 0)) cell.badge = d === clue ? 'ok' : 'bad';
            cell.aria = t('lesson.cell.clue', { where, minute: clue });
        } else if (burnt) {
            Object.assign(cell, heatCell(d, LESSON.maxMinute, isDark.value), { kind: 'burnt', label: String(d) });
            cell.aria = t('lesson.cell.burned', { where, minute: d });
        } else {
            cell.dashed = phase === 2 && i === LESSON.demo;
            cell.aria = t('lesson.cell.empty', { where });
        }
        return cell;
    });
});

const step = computed(() => stepOf(state.value.phase));
const stepLabel = computed(() =>
    state.value.phase === 5 ? t('lesson.complete') : t('lesson.step', { n: step.value, total: 3 })
);

const title = computed(() => {
    const p = state.value.phase;
    return t(`lesson.phase${p}.title`);
});

const body = computed(() => {
    const p = state.value.phase;
    if (p === 4) {
        const msg = puzzleMessage(state.value, replayAt.value > 0);
        return t(msg.key, msg.params);
    }
    return t(`lesson.phase${p}.body`);
});

const buttonLabel = computed(() => {
    const p = state.value.phase;
    return p === 0 || p === 1 || p === 3 ? t(`lesson.phase${p}.button`) : '';
});

const supplyText = computed(() => {
    const left = LESSON.N - placed.value;
    if (left <= 0) return t('lesson.supply.none');
    if (left === 1) return t('lesson.supply.oneLeft');
    return t('lesson.supply.left', { count: left });
});

/* The tray holds the firebreaks still in hand: a filled square per break
   left, a dashed empty slot per break on the board. */
const tray = computed(() => Array.from({ length: LESSON.N }, (_, k) => k < LESSON.N - placed.value));

let themeObserver = null;
onMounted(() => {
    const read = () => (isDark.value = document.documentElement.classList.contains('dark'));
    read();
    themeObserver = new MutationObserver(read);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
    themeObserver?.disconnect();
});
</script>

<template>
    <Head :title="t('lesson.pageTitle')" />

    <main class="bf-lesson">
        <div class="bf-lesson-inner">
            <header class="bf-lesson-top">
                <Link href="/" class="bf-lesson-close" :aria-label="t('lesson.leave')" @click="leave">
                    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </Link>
                <span class="bf-lesson-step">{{ stepLabel }}</span>
                <span class="bf-lesson-spacer" aria-hidden="true"></span>
            </header>

            <div class="bf-lesson-progress" aria-hidden="true">
                <span v-for="k in 3" :key="k" :class="{ 'is-on': k <= step }"></span>
            </div>

            <h1 class="bf-lesson-title">{{ title }}</h1>

            <div class="bf-lesson-board" role="group" :aria-label="t('lesson.boardLabel')">
                <button
                    v-for="cell in cells"
                    :key="cell.i"
                    type="button"
                    class="bf-lesson-cell"
                    :class="[`is-${cell.kind}`, { 'is-target': cell.dashed }]"
                    :style="cell.bg ? { background: cell.bg, color: cell.fg } : null"
                    :aria-label="cell.aria"
                    @click="onTap(cell.i)"
                >
                    <svg v-if="cell.kind === 'spark'" aria-hidden="true" class="bf-lesson-star" viewBox="0 0 24 24">
                        <path d="M12 1.5 14.6 9.4 22.5 12 14.6 14.6 12 22.5 9.4 14.6 1.5 12 9.4 9.4Z" />
                    </svg>
                    <span v-else>{{ cell.label }}</span>
                    <span v-if="cell.badge === 'ok'" class="bf-lesson-badge is-ok" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12.5l4.5 4.5L19 7.5" />
                        </svg>
                    </span>
                    <span v-else-if="cell.badge === 'bad'" class="bf-lesson-badge is-bad" aria-hidden="true">!</span>
                </button>
            </div>

            <section class="bf-lesson-message" aria-live="polite">
                <p>{{ body }}</p>
            </section>

            <div class="bf-lesson-bottom">
                <div v-if="state.phase === 4" class="bf-lesson-supply">
                    <div class="bf-lesson-tray" aria-hidden="true">
                        <span v-for="(inHand, k) in tray" :key="k" :class="{ 'is-in-hand': inHand }"></span>
                    </div>
                    <div class="bf-lesson-supply-copy">
                        <span class="bf-lesson-supply-title">{{ t('lesson.supply.title') }}</span>
                        <span>{{ supplyText }}</span>
                    </div>
                </div>
                <p v-else-if="state.phase === 2" class="bf-lesson-taphint">{{ t('lesson.phase2.hint') }}</p>
                <button v-else-if="buttonLabel" type="button" class="bf-lesson-primary" @click="onAdvance">{{ buttonLabel }}</button>
                <div v-else-if="state.phase === 5" class="bf-lesson-done">
                    <Link :href="FIRST_FIRE_HREF" class="bf-lesson-primary">{{ t('lesson.done.play') }}</Link>
                    <button type="button" class="bf-lesson-secondary" @click="restart">{{ t('lesson.done.again') }}</button>
                </div>
            </div>
        </div>
    </main>
</template>

<style scoped>
/* Colours come from the redesign tokens when they are present, with the
   approved "Progressiekaart" values as fallbacks. */
.bf-lesson {
    --l-ground: var(--bf-ground, #f1f1ee);
    --l-ink: var(--bf-ink, #16181b);
    --l-muted: var(--bf-muted, #55585e);
    --l-line: var(--bf-line, #d8d8d2);
    --l-surface: var(--bf-surface, #ffffff);
    --l-break: var(--bf-break, #16181b);
    --l-cell-line: #b9b9b2;
    min-height: 100dvh;
    background: var(--l-ground);
    color: var(--l-ink);
    font-family: Geist, 'Instrument Sans', system-ui, sans-serif;
}
.bf-lesson-inner {
    box-sizing: border-box;
    max-width: 440px;
    min-height: 100dvh;
    margin: 0 auto;
    padding: 16px 16px max(16px, env(safe-area-inset-bottom));
    display: flex;
    flex-direction: column;
}
.bf-lesson-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 44px;
    flex-shrink: 0;
}
.bf-lesson-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    margin-left: -10px;
    color: var(--l-ink);
    border-radius: 8px;
}
.bf-lesson-close:focus-visible,
.bf-lesson-cell:focus-visible,
.bf-lesson-primary:focus-visible,
.bf-lesson-secondary:focus-visible {
    outline: 3px solid var(--l-ink);
    outline-offset: 2px;
}
.bf-lesson-step {
    font-size: 16px;
    font-weight: 600;
}
.bf-lesson-spacer {
    width: 34px;
}
.bf-lesson-progress {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 6px;
    flex-shrink: 0;
}
.bf-lesson-progress span {
    height: 6px;
    border-radius: 3px;
    background: var(--l-line);
}
.bf-lesson-progress span.is-on {
    background: var(--l-ink);
}
.bf-lesson-title {
    margin: 18px 0 14px;
    height: 32px;
    flex-shrink: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: 'Bricolage Grotesque', system-ui, sans-serif;
    font-weight: 800;
    font-size: 30px;
    line-height: 32px;
    letter-spacing: -0.03em;
}
.bf-lesson-board {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 6px;
    flex-shrink: 0;
    /* keep the whole lesson on one screen: the board gives way on short screens */
    width: min(100%, max(240px, calc(100dvh - 432px)));
    margin-inline: auto;
}
.bf-lesson-cell {
    position: relative;
    aspect-ratio: 1;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    background: var(--l-surface);
    color: var(--l-ink);
    box-shadow: inset 0 0 0 1.5px var(--l-cell-line);
    font-family: 'Bricolage Grotesque', system-ui, sans-serif;
    font-weight: 700;
    font-size: 20px;
    transition: background 200ms ease;
    -webkit-tap-highlight-color: transparent;
}
.bf-lesson-cell.is-spark,
.bf-lesson-cell.is-clue,
.bf-lesson-cell.is-burnt {
    box-shadow: none;
}
.bf-lesson-cell.is-clue {
    font-size: 36px;
}
.bf-lesson-cell.is-break {
    background: var(--l-break);
    box-shadow: none;
}
.bf-lesson-cell.is-target {
    outline: 3px dashed var(--l-ink);
    outline-offset: -8px;
}
.bf-lesson-star {
    width: 34px;
    height: 34px;
    fill: #16181b;
}
.bf-lesson-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 26px;
    height: 26px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: Geist, system-ui, sans-serif;
    font-weight: 600;
    font-size: 16px;
}
.bf-lesson-badge.is-ok {
    background: var(--l-ink);
    color: var(--l-ground);
}
.bf-lesson-badge.is-bad {
    background: var(--l-surface);
    color: var(--l-ink);
    box-shadow: inset 0 0 0 2.5px var(--l-ink);
}
.bf-lesson-message {
    margin-top: 16px;
    flex: 1 1 0;
    min-height: 112px;
}
.bf-lesson-message p {
    margin: 0;
    font-size: 19px;
    line-height: 1.4;
    text-wrap: pretty;
}
.bf-lesson-bottom {
    margin-top: 12px;
    height: 140px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    gap: 12px;
}
.bf-lesson-supply {
    display: flex;
    align-items: center;
    gap: 14px;
    height: 76px;
    box-sizing: border-box;
    padding: 0 16px;
    border-radius: 8px;
    background: var(--l-surface);
    box-shadow: inset 0 0 0 1.5px var(--l-cell-line);
}
.bf-lesson-tray {
    display: flex;
    gap: 8px;
}
.bf-lesson-tray span {
    width: 48px;
    height: 48px;
    box-sizing: border-box;
    border-radius: 6px;
    border: 2px dashed #8a8a84;
}
.bf-lesson-tray span.is-in-hand {
    border: 0;
    background: var(--l-break);
}
.bf-lesson-supply-copy {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    font-size: 16px;
    line-height: 20px;
}
.bf-lesson-supply-title {
    font-size: 18px;
    font-weight: 600;
    line-height: 22px;
}
.bf-lesson-taphint {
    margin: 0 0 20px;
    text-align: center;
    font-size: 18px;
    font-weight: 600;
}
.bf-lesson-primary {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 64px;
    border: 0;
    border-radius: 8px;
    background: var(--l-ink);
    color: var(--l-ground);
    text-decoration: none;
    font-family: 'Bricolage Grotesque', system-ui, sans-serif;
    font-weight: 700;
    font-size: 22px;
    cursor: pointer;
}
.bf-lesson-secondary {
    height: 56px;
    border: 2px solid var(--l-ink);
    border-radius: 8px;
    background: transparent;
    color: var(--l-ink);
    font-family: Geist, system-ui, sans-serif;
    font-weight: 600;
    font-size: 18px;
    cursor: pointer;
}
.bf-lesson-done {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
:global(.dark) .bf-lesson {
    --l-cell-line: #33373d;
}
@media (prefers-reduced-motion: reduce) {
    .bf-lesson-cell {
        transition: none;
    }
}
</style>
