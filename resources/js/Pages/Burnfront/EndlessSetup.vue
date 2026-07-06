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

function meta(config, key) {
    const size = `${config.rows}×${config.cols} grid · ${config.breaks} firebreaks`;
    const base = config.timed ? size : `${size} · untimed`;
    const best = props.bestTimes[key];
    return best?.bestTimeMs != null ? `${base} · best ${fmtClock(best.bestTimeMs)}` : base;
}

// Trailing part of meta() after the "RxC grid · N firebreaks" prefix (e.g. " · untimed · best 01:23"),
// kept in sync with meta() so the highlighted-number markup below never drifts from its text.
function metaSuffix(config, key) {
    const prefix = `${config.rows}×${config.cols} grid · ${config.breaks} firebreaks`;
    return meta(config, key).slice(prefix.length);
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

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-6 pb-12 sm:pt-10 sm:pb-16">
        <SiteBar :back="{ href: '/', text: 'Menu' }" />

        <header class="flex flex-col gap-2">
            <h1 class="font-staatliches text-[clamp(40px,9vw,56px)] leading-[0.95] font-normal tracking-[.035em] text-stock">
                CHOOSE A DIFFICULTY
            </h1>
            <p class="max-w-[52ch] text-ash">Pick a tier to generate a fresh incident. Come back here any time to switch tiers.</p>
        </header>

        <nav class="flex flex-col gap-3" aria-label="Difficulty tiers">
            <Link v-for="(config, key) in difficulties" :key="key" :href="`/endless/play?difficulty=${key}`" class="bf-row">
                <span
                    class="h-[38px] w-[38px] shrink-0 rounded-md border border-rule-2"
                    :style="{
                        backgroundImage:
                            'linear-gradient(var(--color-rule-2) 1px,transparent 1px),linear-gradient(90deg,var(--color-rule-2) 1px,transparent 1px)',
                        backgroundSize: `${Math.max(6, Math.floor(36 / config.cols))}px ${Math.max(6, Math.floor(36 / config.rows))}px`,
                    }"
                    aria-hidden="true"
                ></span>
                <span class="flex flex-1 flex-col gap-0.5">
                    <span class="font-staatliches text-[21px] leading-none tracking-[.02em] text-stock">{{ config.label }}</span>
                    <span class="font-mono text-[11px] tracking-[.06em] text-ash-dim uppercase tabular-nums">
                        <span class="text-steel">{{ config.rows }}×{{ config.cols }}</span> grid ·
                        <span class="text-steel">{{ config.breaks }}</span> firebreaks{{ metaSuffix(config, key) }}
                    </span>
                </span>
                <span class="font-staatliches text-lg text-ash-dim" aria-hidden="true">▸</span>
            </Link>

            <div class="bf-row is-dashed flex-col items-stretch gap-3">
                <button
                    type="button"
                    class="flex cursor-pointer flex-col gap-1.5 text-left"
                    :aria-expanded="customOpen"
                    @click="customOpen = !customOpen"
                >
                    <span class="font-staatliches text-[21px] leading-none tracking-[.02em] text-stock">Custom</span>
                    <span class="text-[13px] text-ash">Set your own grid size and firebreak count.</span>
                </button>

                <div v-if="customOpen" class="flex flex-col gap-3">
                    <div class="grid grid-cols-3 gap-3">
                        <label class="flex flex-col gap-1 text-[11px] tracking-[.08em] text-ash-dim uppercase">
                            Rows
                            <input
                                v-model.number="rows"
                                type="number"
                                :min="customBounds.minDim"
                                :max="customBounds.maxDim"
                                class="rounded-md border border-rule-2 bg-folder px-2.5 py-1.5 text-sm text-stock font-mono tabular-nums"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-[11px] tracking-[.08em] text-ash-dim uppercase">
                            Cols
                            <input
                                v-model.number="cols"
                                type="number"
                                :min="customBounds.minDim"
                                :max="customBounds.maxDim"
                                class="rounded-md border border-rule-2 bg-folder px-2.5 py-1.5 text-sm text-stock font-mono tabular-nums"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-[11px] tracking-[.08em] text-ash-dim uppercase">
                            Breaks
                            <input
                                v-model.number="breaks"
                                type="number"
                                :min="customBounds.minBreaks"
                                :max="maxBreaks"
                                class="rounded-md border border-rule-2 bg-folder px-2.5 py-1.5 text-sm text-stock font-mono tabular-nums"
                                @change="clampBreaks"
                            />
                        </label>
                    </div>
                    <p class="text-[11px] text-ash-dim">
                        {{ customBounds.minDim }}–{{ customBounds.maxDim }} per side · up to {{ maxBreaks }} firebreaks
                        for this grid.
                    </p>
                    <Link
                        :href="customValid ? customHref : '#'"
                        class="bf-btn bf-btn-primary self-start"
                        :class="{ 'pointer-events-none opacity-35': !customValid }"
                        :aria-disabled="!customValid"
                    >
                        Start custom fire
                    </Link>
                </div>
            </div>
        </nav>
    </main>
</template>
