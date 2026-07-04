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
            <h1 class="font-staatliches text-[clamp(40px,9vw,56px)] leading-[0.95] font-normal tracking-[.035em] text-paper">
                CHOOSE A DIFFICULTY
            </h1>
            <p class="max-w-[52ch] text-ash">Pick a tier to generate a fresh incident. Come back here any time to switch tiers.</p>
        </header>

        <nav class="flex flex-col gap-3" aria-label="Difficulty tiers">
            <Link v-for="(config, key) in difficulties" :key="key" :href="`/endless/play?difficulty=${key}`" class="bf-tile">
                <span class="bf-tile-title">{{ config.label }}</span>
                <span class="bf-tile-meta">{{ meta(config, key) }}</span>
            </Link>

            <div class="flex flex-col gap-3 rounded-md border border-line px-5 py-4">
                <button
                    type="button"
                    class="flex cursor-pointer flex-col gap-1.5 text-left"
                    :aria-expanded="customOpen"
                    @click="customOpen = !customOpen"
                >
                    <span class="bf-tile-title">Custom</span>
                    <span class="bf-tile-desc">Set your own grid size and firebreak count.</span>
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
                                class="rounded-[5px] border border-line bg-transparent px-2.5 py-1.5 text-sm text-paper tabular-nums normal-case"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-[11px] tracking-[.08em] text-ash-dim uppercase">
                            Cols
                            <input
                                v-model.number="cols"
                                type="number"
                                :min="customBounds.minDim"
                                :max="customBounds.maxDim"
                                class="rounded-[5px] border border-line bg-transparent px-2.5 py-1.5 text-sm text-paper tabular-nums normal-case"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-[11px] tracking-[.08em] text-ash-dim uppercase">
                            Breaks
                            <input
                                v-model.number="breaks"
                                type="number"
                                :min="customBounds.minBreaks"
                                :max="maxBreaks"
                                class="rounded-[5px] border border-line bg-transparent px-2.5 py-1.5 text-sm text-paper tabular-nums normal-case"
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
