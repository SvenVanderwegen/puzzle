<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';

const props = defineProps({
    tiers: { type: Array, default: () => [] }, // {difficulty, label, timed, solvedCount, bestTimeMs}
    career: {
        type: Object,
        default: () => ({
            rank: { title: '', totalSolved: 0, currentThreshold: 0, nextTitle: null, nextThreshold: null },
            badges: [],
        }),
    },
});

// Progress toward the next rank threshold, for the XP bar under the rank
// card. A null nextThreshold means the rank is maxed out — show it full.
const rankProgressPercent = computed(() => {
    const { totalSolved, currentThreshold, nextThreshold } = props.career.rank;
    if (nextThreshold == null) return 100;
    const span = nextThreshold - currentThreshold;
    if (span <= 0) return 100;
    return Math.min(100, Math.max(0, ((totalSolved - currentThreshold) / span) * 100));
});
</script>

<template>
    <Head title="Game History — Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-6 pb-16">
        <SiteBar :back="{ href: '/account', text: 'Account' }" />

        <header class="flex flex-col gap-2">
            <p class="bf-eyebrow">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-stock">Game History</h1>
            <p class="max-w-[52ch] text-ash">Every endless tier you&rsquo;ve closed at least once, and your fastest verified board for it.</p>
        </header>

        <section aria-label="Career rank" class="flex flex-col gap-3 rounded-lg border border-rule-2 bg-folder px-5 py-4">
            <div class="flex flex-col gap-1.5">
                <span class="bf-tile-title">{{ career.rank.title }}</span>
                <span class="bf-tile-meta">
                    {{ career.rank.totalSolved }} incident{{ career.rank.totalSolved === 1 ? '' : 's' }} closed
                    <template v-if="career.rank.nextTitle">
                        &middot; {{ career.rank.nextThreshold - career.rank.totalSolved }} to {{ career.rank.nextTitle }}</template
                    >
                </span>
                <div class="bf-xp-track">
                    <div class="bf-xp-fill" :style="{ width: rankProgressPercent + '%' }"></div>
                </div>
            </div>
            <ul class="flex flex-wrap gap-2" aria-label="Badges">
                <li
                    v-for="(badge, i) in career.badges"
                    :key="badge.key"
                    class="rounded px-2 py-0.5 text-xs font-staatliches uppercase tracking-[.03em]"
                    :class="badge.earned ? (i % 3 === 1 ? 'border border-steel text-steel' : 'border border-ember text-ember') : 'border border-dashed border-rule-2 text-ash-dim'"
                    :title="badge.description"
                >
                    {{ badge.label }}
                </li>
            </ul>
        </section>

        <section aria-label="Endless tier records" class="flex flex-col gap-3">
            <p class="bf-eyebrow">Fastest verified &middot; by tier</p>
            <div
                v-for="tier in tiers"
                :key="tier.difficulty"
                class="bf-row items-center justify-between"
                :class="tier.solvedCount === 0 ? 'border-rule opacity-60' : ''"
            >
                <span class="font-staatliches text-[19px] leading-none tracking-[.02em] text-stock">{{ tier.label }}</span>
                <span v-if="tier.timed && tier.bestTimeMs != null" class="shrink-0 font-mono font-bold text-ember-hi">
                    {{ fmtClock(tier.bestTimeMs) }}
                </span>
                <span v-else-if="tier.solvedCount > 0" class="shrink-0 font-mono text-ash-dim uppercase">{{ tier.solvedCount }} closed</span>
                <span v-else class="shrink-0 font-mono text-ash-dim uppercase">Not yet attempted</span>
            </div>
        </section>
    </main>
</template>
