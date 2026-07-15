<script setup>
import { computed, ref } from 'vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';

const emit = defineEmits(['complete']);

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

const FLAME = '<svg viewBox="0 0 24 24" style="display:inline-block;width:.75em;height:1em;vertical-align:-.14em;filter:drop-shadow(0 0 4px rgba(255,122,45,.5))" aria-hidden="true"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" fill="#ff7a2d"/><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" fill="#ffd36b" transform="translate(5.4 8.2) scale(.55)"/></svg><span class="sr-only">flame</span>';

const STEPS = [
    {
        title: 'Watch the spread',
        caption: `Fire starts on the ${FLAME} at minute 0 and moves one cell per minute — up, down, left and right. <span class="bf-stress">Firebreaks stop it.</span>`,
        boardLabel: 'Training grid at minute 4. Fire spreads outward from the flame while two shaded firebreak cells remain unburned.',
        t: 4,
        visibleBreaks: 2,
    },
    {
        title: 'Read the evidence',
        caption: `The numbered cell says 5, but it sits only 3 steps from the ${FLAME}. <span class="bf-stress">A hidden break must block every shorter route.</span>`,
        boardLabel: 'Training grid with the short route from the flame to the clue marked 5 shown as blocked.',
        t: 7,
        visibleBreaks: 2,
        blocked: true,
    },
    {
        title: 'Trace the detour',
        caption: 'With that route blocked, the fire travels around the line and reaches the clue at minute 5 exactly. Numbers record the shortest open route.',
        boardLabel: 'Training grid showing a five-step detour from the flame to the clue marked 5.',
        t: 7,
        visibleBreaks: 2,
        route: true,
    },
    {
        title: 'Place the hidden line',
        caption: 'In a live case the breaks begin hidden. Tap an open cell once for a firebreak, again for a proven-open dot, and a third time to clear it.',
        boardLabel: 'Training grid reset with both firebreaks hidden.',
        t: -1,
        visibleBreaks: 0,
    },
    {
        title: 'Contain the incident',
        caption: '<span class="bf-stress">Place exactly N firebreaks.</span> At N/N the board checks itself: every number must burn at its recorded minute and every other cell must still be reached.',
        boardLabel: 'Completed training grid with two firebreaks and every open cell reached at its recorded minute.',
        t: 7,
        visibleBreaks: 2,
    },
];

const burnBg = TIMES.map((t) => {
    const f = 1 - (0.25 + 0.6 * (t < 0 ? 0 : t / MAXT));
    const mix = (a, c) => Math.round(c + (a - c) * f);
    return `rgb(${mix(255, 255)},${mix(216, 138)},${mix(107, 61)})`;
});

const stepIndex = ref(0);
const completed = ref(false);
const step = computed(() => STEPS[stepIndex.value]);
const minuteLabel = computed(() => (step.value.t < 0 ? '–' : String(step.value.t)));
const focused = computed(() => Boolean(step.value.route || step.value.blocked));
const atStart = computed(() => stepIndex.value === 0);
const atEnd = computed(() => stepIndex.value === STEPS.length - 1);

function cellState(i) {
    const current = step.value;
    const isBreak = BREAKS.includes(i);
    const shown = isBreak && BREAKS.indexOf(i) < current.visibleBreaks;
    const burnt = !isBreak && current.t >= 0 && TIMES[i] <= current.t;
    return {
        isSpark: i === SPARK,
        isClue: CLUES[i] !== undefined,
        isBreak: shown,
        isBurnt: burnt,
        isOk: burnt && CLUES[i] !== undefined,
        isBlocked: Boolean(current.blocked) && DIRECT.includes(i),
        isX: Boolean(current.blocked) && i === XCELL,
        isRoute: Boolean(current.route) && DETOUR.includes(i),
    };
}

function cellClasses(i) {
    const state = cellState(i);
    return {
        'bf-dcell': true,
        'is-spark': state.isSpark,
        'is-clue': state.isClue,
        'is-break': state.isBreak,
        'is-burnt': state.isBurnt,
        'is-ok': state.isOk,
        'is-blocked': state.isBlocked,
        'is-x': state.isX,
        'is-route': state.isRoute,
    };
}

function cellText(i) {
    if (CLUES[i] !== undefined) return String(CLUES[i]);
    const state = cellState(i);
    return state.isBurnt ? String(TIMES[i]) : '';
}

function previous() {
    if (!atStart.value) {
        completed.value = false;
        stepIndex.value -= 1;
    }
}

function next() {
    if (atEnd.value) {
        completed.value = true;
        emit('complete');
        return;
    }

    stepIndex.value += 1;
}

function goToStep(index) {
    completed.value = false;
    stepIndex.value = index;
}

function replay() {
    completed.value = false;
    stepIndex.value = 0;
}
</script>

<template>
    <div class="bf-demo">
        <header class="bf-demo-progress">
            <span>Guided briefing</span>
            <strong>Step {{ stepIndex + 1 }} of {{ STEPS.length }}</strong>
        </header>

        <div>
            <p class="bf-demo-step-title">{{ step.title }}</p>
            <p class="mt-1 min-h-[4.5em] max-w-[60ch] text-[14.5px] leading-normal text-stock" aria-live="polite" v-html="step.caption"></p>
        </div>

        <div class="flex flex-wrap items-start gap-[18px]">
            <div
                class="bf-demo-grid"
                :class="{ 'is-focus': focused }"
                role="img"
                :aria-label="step.boardLabel"
            >
                <div
                    v-for="i in R * C"
                    :key="i - 1"
                    :class="cellClasses(i - 1)"
                    :style="{ '--burn-bg': burnBg[i - 1] }"
                    aria-hidden="true"
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
                <div class="bf-chip">
                    <span class="bf-chip-key">Breaks</span>
                    <span class="bf-chip-value">{{ step.visibleBreaks }}/2</span>
                </div>
            </div>
        </div>

        <nav class="bf-demo-controls" aria-label="Briefing steps">
            <button type="button" class="bf-btn" :disabled="atStart" @click="previous">Back</button>
            <div class="bf-demo-dots">
                <button
                    v-for="(_, index) in STEPS"
                    :key="index"
                    type="button"
                    class="bf-demo-dot-button"
                    :class="{ 'is-active': index === stepIndex }"
                    :aria-label="`Go to briefing step ${index + 1}`"
                    :aria-current="index === stepIndex ? 'step' : undefined"
                    @click="goToStep(index)"
                >
                    <span class="bf-demo-dot-mark" aria-hidden="true"></span>
                </button>
            </div>
            <button v-if="!completed" type="button" class="bf-btn bf-btn-primary" @click="next">
                {{ atEnd ? 'Finish briefing' : 'Next step' }}
            </button>
            <button v-else type="button" class="bf-btn" @click="replay">Replay</button>
        </nav>

        <p v-if="completed" class="text-center text-[13px] font-semibold text-verify" role="status" aria-live="polite">
            Briefing complete. You are ready for a practice incident.
        </p>
    </div>
</template>

<style scoped>
.bf-demo-dot-button {
    display: inline-flex;
    width: 28px !important;
    height: 28px !important;
    align-items: center;
    justify-content: center;
    border-color: transparent !important;
    background: transparent !important;
    box-shadow: none !important;
}

.bf-demo-dot-mark {
    width: 10px;
    height: 10px;
    border: 1px solid var(--color-rule-2);
    border-radius: 2px;
    background: var(--color-raised);
}

.bf-demo-dot-button.is-active .bf-demo-dot-mark {
    border-color: var(--color-ember);
    background: var(--color-ember);
    box-shadow: 0 0 8px rgba(255, 122, 45, 0.35);
}
</style>
