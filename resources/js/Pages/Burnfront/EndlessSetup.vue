<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';

const props = defineProps({
    difficulties: { type: Object, default: () => ({}) },
    customBounds: {
        type: Object,
        default: () => ({ minDim: 4, maxDim: 10, minBreaks: 2, breaksRatio: 0.28 }),
    },
    bestTimes: { type: Object, default: () => ({}) }, // signed-in players only: difficulty => {solvedCount, bestTimeMs}
});

const tierDescriptions = ['Recommended · beginner', 'Standard', 'Advanced', 'Expert', 'Untimed · sparse clues'];

function meta(config, key, index) {
    const base = `${tierDescriptions[index] ?? 'Field assignment'} · ${config.breaks} firebreaks`;
    const best = props.bestTimes[key];
    return best?.bestTimeMs != null ? `${base} · best ${fmtClock(best.bestTimeMs)}` : base;
}

const customOpen = ref(false);
const rows = ref(6);
const cols = ref(6);
const breaks = ref(8);

const maxBreaks = computed(() =>
    Math.max(props.customBounds.minBreaks, Math.floor(rows.value * cols.value * props.customBounds.breaksRatio))
);

const customValid = computed(() => {
    const { minDim, maxDim, minBreaks } = props.customBounds;
    return (
        Number.isInteger(rows.value) &&
        rows.value >= minDim &&
        rows.value <= maxDim &&
        Number.isInteger(cols.value) &&
        cols.value >= minDim &&
        cols.value <= maxDim &&
        Number.isInteger(breaks.value) &&
        breaks.value >= minBreaks &&
        breaks.value <= maxBreaks.value
    );
});

const customHref = computed(
    () => `/endless/play?difficulty=custom&rows=${rows.value}&cols=${cols.value}&breaks=${breaks.value}`
);

function clampBreaks() {
    if (!Number.isInteger(breaks.value)) return;
    if (breaks.value > maxBreaks.value) breaks.value = maxBreaks.value;
    if (breaks.value < props.customBounds.minBreaks) breaks.value = props.customBounds.minBreaks;
}
</script>

<template>
    <Head title="Endless · Burnfront" />

    <main class="mx-auto flex max-w-[720px] flex-col gap-7 px-4 pt-3 pb-12 sm:pt-5 sm:pb-16">
        <SiteBar :back="{ href: '/', text: 'Menu' }" />

        <header class="flex flex-col gap-2">
            <h1 class="font-staatliches text-[clamp(40px,9vw,56px)] leading-[0.95] font-normal tracking-[.035em] text-stock">
                CHOOSE CREW RANK
            </h1>
            <p class="max-w-[52ch] text-ash">Pick a tier to generate a fresh incident. Lookout is the recommended first assignment.</p>
        </header>

        <nav class="flex flex-col gap-3" aria-label="Difficulty tiers">
            <Link
                v-for="(config, key, index) in difficulties"
                :key="key"
                :href="`/endless/play?difficulty=${key}`"
                class="bf-row bf-difficulty-row"
            >
                <span class="bf-difficulty-index">{{ String(index + 1).padStart(2, '0') }}</span>
                <span
                    class="bf-difficulty-grid"
                    :style="{
                        backgroundImage:
                            'linear-gradient(var(--color-rule-2) 1px,transparent 1px),linear-gradient(90deg,var(--color-rule-2) 1px,transparent 1px)',
                        backgroundSize: `${Math.max(6, Math.floor(36 / config.cols))}px ${Math.max(6, Math.floor(36 / config.rows))}px`,
                    }"
                    aria-hidden="true"
                ></span>
                <span class="flex flex-1 flex-col gap-0.5">
                    <span class="text-[18px] leading-tight font-semibold text-stock sm:text-[19px]">{{ config.label }}</span>
                    <span class="font-mono text-[12px] font-semibold tracking-[.07em] text-ash-dim uppercase tabular-nums">
                        {{ meta(config, key, index) }}
                    </span>
                </span>
                <span class="bf-difficulty-action"><em>Deploy</em> <b aria-hidden="true">&rarr;</b></span>
            </Link>

            <div class="bf-row is-dashed flex-col items-stretch gap-3 p-4">
                <button
                    type="button"
                    class="flex min-h-11 cursor-pointer flex-col justify-center gap-1.5 text-left"
                    :aria-expanded="customOpen"
                    aria-controls="custom-incident-fields"
                    @click="customOpen = !customOpen"
                >
                    <span class="flex items-center justify-between gap-3 text-[19px] leading-none font-semibold text-stock">
                        Custom incident <b class="font-mono text-ember" aria-hidden="true">{{ customOpen ? '−' : '+' }}</b>
                    </span>
                    <span class="text-[13px] text-ash">Set your own grid size and firebreak count.</span>
                </button>

                <div v-if="customOpen" id="custom-incident-fields" class="flex flex-col gap-3">
                    <div class="grid grid-cols-3 gap-3">
                        <label class="flex flex-col gap-1 text-[11px] tracking-[.08em] text-ash-dim uppercase">
                            Rows
                            <input
                                v-model.number="rows"
                                type="number"
                                :min="customBounds.minDim"
                                :max="customBounds.maxDim"
                                class="bf-input bf-input-compact font-mono tabular-nums"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-[11px] tracking-[.08em] text-ash-dim uppercase">
                            Cols
                            <input
                                v-model.number="cols"
                                type="number"
                                :min="customBounds.minDim"
                                :max="customBounds.maxDim"
                                class="bf-input bf-input-compact font-mono tabular-nums"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-[11px] tracking-[.08em] text-ash-dim uppercase">
                            Breaks
                            <input
                                v-model.number="breaks"
                                type="number"
                                :min="customBounds.minBreaks"
                                :max="maxBreaks"
                                class="bf-input bf-input-compact font-mono tabular-nums"
                                @change="clampBreaks"
                            />
                        </label>
                    </div>
                    <p class="text-[11px] text-ash-dim">
                        {{ customBounds.minDim }}–{{ customBounds.maxDim }} per side · up to {{ maxBreaks }} firebreaks
                        for this grid.
                    </p>
                    <Link v-if="customValid" :href="customHref" class="bf-btn bf-btn-primary self-start">Start custom incident</Link>
                    <button v-else type="button" class="bf-btn bf-btn-primary self-start" disabled>Start custom incident</button>
                </div>
            </div>
        </nav>
    </main>
</template>
