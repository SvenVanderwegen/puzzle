<script setup>
import { Head, Link } from '@inertiajs/vue3';
import SiteBar from '@/Components/SiteBar.vue';
import ShieldMarker from '@/Components/ShieldMarker.vue';
import RubberStamp from '@/Components/RubberStamp.vue';

const props = defineProps({
    progress: { type: Object, required: true }, // {level, chapterKey, chapterLabel, xpIntoLevel, xpToNextLevel, totalXp, maxed}
    chapters: { type: Array, default: () => [] }, // [{key, label, levels: [{level, label, state: 'locked'|'current'|'reached'}]}]
    totalLevels: { type: Number, default: 20 },
});

const xpPct = props.progress.maxed
    ? 100
    : Math.min(100, Math.round((props.progress.xpIntoLevel / props.progress.xpToNextLevel) * 100));

const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'];

/* A district (chapter) reads as ACTIVE the moment it holds the current
   level, CLEARED once every level in it has been passed, and SEALED
   otherwise — mirrors the linear level progression the server already
   enforces (there is always exactly one 'current' level campaign-wide). */
function districtStatus(chapter) {
    if (chapter.levels.some((l) => l.state === 'current')) return 'active';
    if (chapter.levels.every((l) => l.state === 'reached')) return 'cleared';
    return 'sealed';
}

const DISTRICT_STAMP = { cleared: 'ember', active: 'flame', sealed: 'steel' };
const DISTRICT_LABEL = { cleared: 'Cleared', active: 'Active', sealed: 'Sealed' };

function markerState(node) {
    if (node.state === 'reached') return 'cleared';
    if (node.state === 'current') return 'current';
    return 'sealed';
}

/* The dashed containment line leading INTO a node: ember once the fire's
   past that point, ash ahead of it. No line before the very first node in
   the whole campaign — there's nothing upstream of it to connect from. */
function lineClass(node) {
    return node.state === 'locked' ? 'is-ahead' : 'is-cleared';
}
</script>

<template>
    <Head title="Campaign · Burnfront" />

    <main class="mx-auto flex max-w-[720px] flex-col gap-6 px-4 pt-3 pb-16 sm:pt-5">
        <SiteBar :back="{ href: '/', text: 'Menu' }" />

        <header class="flex flex-col gap-2.5">
            <div class="flex items-end justify-between gap-3">
                <h1 class="font-staatliches text-[clamp(38px,9vw,52px)] leading-[0.9] font-normal tracking-[.02em] text-stock">
                    Campaign
                </h1>
                <p class="shrink-0 text-right font-mono text-[12px] font-semibold tracking-[.1em] text-ash-dim uppercase">
                    {{ chapters.length }} districts<br />{{ totalLevels }} incidents
                </p>
            </div>

            <div class="bf-xp-card">
                <div class="flex items-baseline justify-between font-mono text-[12px] font-semibold tracking-[.08em] text-ash uppercase">
                    <span>Rank &middot; {{ progress.chapterLabel }}</span>
                    <span v-if="!progress.maxed" class="font-bold text-ember-hi">{{ progress.xpIntoLevel }}/{{ progress.xpToNextLevel }} XP</span>
                </div>
                <div class="bf-xp-track"><div class="bf-xp-fill" :style="{ width: xpPct + '%' }"></div></div>
                <p v-if="progress.maxed" class="text-[12.5px] text-ember">Every case file in the record is closed.</p>
            </div>
        </header>

        <div class="flex flex-col items-center">
            <template v-for="(chapter, ci) in chapters" :key="chapter.key">
                <div class="bf-district">
                    <span class="bf-district-title" :class="districtStatus(chapter) === 'sealed' ? 'text-ash-dim' : 'text-stock'">
                        {{ ROMAN[ci] ?? chapter.key }} &middot; {{ chapter.label.toUpperCase() }}
                    </span>
                    <span class="bf-district-rule"></span>
                    <RubberStamp :tone="DISTRICT_STAMP[districtStatus(chapter)]" size="sm" :rotate="ci % 2 === 0 ? -3 : 2">
                        {{ DISTRICT_LABEL[districtStatus(chapter)] }}
                    </RubberStamp>
                </div>

                <template v-for="(node, ni) in chapter.levels" :key="node.level">
                    <div v-if="!(ci === 0 && ni === 0)" class="bf-trail-line" :class="lineClass(node)"></div>

                    <Link
                        v-if="node.state === 'current'"
                        href="/campaign/play"
                        class="rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame"
                        :aria-label="`${node.label}, current case`"
                    >
                        <ShieldMarker state="current" :label="String(node.level).padStart(2, '0')" />
                    </Link>
                    <div v-else :aria-label="`${node.label}, ${node.state === 'reached' ? 'closed' : 'locked'}`">
                        <ShieldMarker :state="markerState(node)" :label="String(node.level).padStart(2, '0')" />
                    </div>

                    <p v-if="node.state === 'current'" class="mt-1 font-mono text-[12px] font-semibold tracking-[.1em] text-ember-hi uppercase">
                        You are here &middot; {{ node.label }}
                    </p>
                </template>
            </template>
        </div>
    </main>
</template>
