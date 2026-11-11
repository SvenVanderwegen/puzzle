<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { fmtClock } from '@/lib/burnfront-engine';
import RowArrow from '@/Components/RowArrow.vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';

const props = defineProps({
    difficulties: { type: Object, default: () => ({}) },
    customBounds: {
        type: Object,
        default: () => ({ minDim: 4, maxDim: 10, minBreaks: 2, breaksRatio: 0.28 }),
    },
    bestTimes: { type: Object, default: () => ({}) }, // signed-in players only: difficulty => {solvedCount, bestTimeMs}
});

function name(config, key) {
    const k = `common.difficulty.${key}`;
    const v = t(k);
    return v === k ? config.label : v;
}

function meta(config) {
    return t(config.timed === false ? 'practice.metaNoClock' : 'practice.meta', { rows: config.rows, cols: config.cols, breaks: config.breaks });
}

function best(key) {
    const b = props.bestTimes[key];
    return b?.bestTimeMs != null ? t('practice.best', { time: fmtClock(b.bestTimeMs) }) : '';
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
    <Head :title="t('practice.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/', text: t('common.home') }" />

        <main class="flex flex-col gap-4">
            <header class="bf-page-head pb-1">
                <p class="bf-eyebrow">{{ t('practice.eyebrow') }}</p>
                <h1 class="bf-page-title">{{ t('practice.title') }}</h1>
                <p class="bf-lede">{{ t('practice.lead') }}</p>
            </header>

            <nav class="bf-list" :aria-label="t('practice.eyebrow')">
                <Link
                    v-for="(config, key) in difficulties"
                    :key="key"
                    :href="`/endless/play?difficulty=${key}`"
                    class="bf-row"
                    :aria-label="t('practice.start', { name: name(config, key) })"
                >
                    <span
                        class="bf-size-thumb"
                        :style="{ gridTemplateColumns: `repeat(${config.cols}, 1fr)`, gridTemplateRows: `repeat(${config.rows}, 1fr)` }"
                        aria-hidden="true"
                    >
                        <i v-for="n in config.rows * config.cols" :key="n"></i>
                    </span>
                    <span class="bf-row-text">
                        <span class="bf-row-title">{{ name(config, key) }}</span>
                        <span class="bf-row-sub tabular-nums">{{ meta(config) }}</span>
                        <span v-if="best(key)" class="bf-row-sub tabular-nums">{{ best(key) }}</span>
                    </span>
                    <RowArrow />
                </Link>

                <div class="bf-row is-dashed flex-col items-stretch gap-3">
                    <button
                        type="button"
                        class="flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 border-0 bg-transparent p-0 text-left text-ink"
                        :aria-expanded="customOpen"
                        aria-controls="custom-size-fields"
                        @click="customOpen = !customOpen"
                    >
                        <span class="bf-row-text">
                            <span class="bf-row-title">{{ t('practice.custom.title') }}</span>
                            <span class="bf-row-sub">{{ t('practice.custom.help') }}</span>
                        </span>
                        <span class="bf-mono-label text-[20px]" aria-hidden="true">{{ customOpen ? '−' : '+' }}</span>
                    </button>

                    <div v-if="customOpen" id="custom-size-fields" class="flex flex-col gap-3">
                        <div class="grid grid-cols-3 gap-3">
                            <label class="bf-number-field">
                                {{ t('practice.custom.rows') }}
                                <input v-model.number="rows" type="number" inputmode="numeric" :min="customBounds.minDim" :max="customBounds.maxDim" class="bf-input tabular-nums" />
                            </label>
                            <label class="bf-number-field">
                                {{ t('practice.custom.cols') }}
                                <input v-model.number="cols" type="number" inputmode="numeric" :min="customBounds.minDim" :max="customBounds.maxDim" class="bf-input tabular-nums" />
                            </label>
                            <label class="bf-number-field">
                                {{ t('practice.custom.breaks') }}
                                <input
                                    v-model.number="breaks"
                                    type="number"
                                    inputmode="numeric"
                                    :min="customBounds.minBreaks"
                                    :max="maxBreaks"
                                    class="bf-input tabular-nums"
                                    @change="clampBreaks"
                                />
                            </label>
                        </div>
                        <p class="bf-row-sub m-0">
                            {{ t('practice.custom.limits', { min: customBounds.minDim, max: customBounds.maxDim, maxBreaks }) }}
                        </p>
                        <Link v-if="customValid" :href="customHref" class="bf-btn bf-btn-primary w-full sm:w-auto sm:self-start">{{ t('practice.custom.start') }}</Link>
                        <button v-else type="button" class="bf-btn bf-btn-primary w-full sm:w-auto sm:self-start" disabled>{{ t('practice.custom.start') }}</button>
                    </div>
                </div>
            </nav>
        </main>
    </div>
</template>
