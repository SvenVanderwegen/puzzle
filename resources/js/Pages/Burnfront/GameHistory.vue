<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import RowArrow from '@/Components/RowArrow.vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';
import { plural, tOr } from '@/lib/plural';

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

// Progress toward the next rank. A null nextThreshold means the top rank.
const rankProgressPercent = computed(() => {
    const { totalSolved, currentThreshold, nextThreshold } = props.career.rank;
    if (nextThreshold == null) return 100;
    const span = nextThreshold - currentThreshold;
    if (span <= 0) return 100;
    return Math.min(100, Math.max(0, ((totalSolved - currentThreshold) / span) * 100));
});

// Rank titles come from the server keyed by threshold; the plain words live in the locale file.
const rankTitle = (threshold, fallback) => tOr(`history.rank.${threshold}`, fallback);
const tierName = (tier) => tOr(`common.difficulty.${tier.difficulty}`, tier.label);
</script>

<template>
    <Head :title="t('history.games.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/account', text: t('history.back') }" />

        <main class="flex flex-col gap-4">
            <header class="bf-page-head pb-1">
                <p class="bf-eyebrow">{{ t('history.games.eyebrow') }}</p>
                <h1 class="bf-page-title">{{ t('history.games.title') }}</h1>
                <p class="bf-lede">{{ t('history.games.lead') }}</p>
            </header>

            <section class="bf-card flex flex-col gap-3" :aria-label="rankTitle(career.rank.currentThreshold, career.rank.title)">
                <div class="flex flex-col gap-1">
                    <span class="bf-row-title text-[20px]">{{ rankTitle(career.rank.currentThreshold, career.rank.title) }}</span>
                    <span class="bf-row-sub">
                        {{ plural('history.games.done', career.rank.totalSolved) }}<template v-if="career.rank.nextTitle">
                            · {{ t('history.games.toNext', { count: career.rank.nextThreshold - career.rank.totalSolved, title: rankTitle(career.rank.nextThreshold, career.rank.nextTitle) }) }}</template
                        >
                    </span>
                </div>
                <span class="bf-progress" aria-hidden="true"><span :style="{ width: rankProgressPercent + '%' }"></span></span>

                <h2 class="bf-row-sub m-0 mt-1">{{ t('history.games.badges') }}</h2>
                <ul class="bf-badges">
                    <li
                        v-for="badge in career.badges"
                        :key="badge.key"
                        :class="{ 'is-earned': badge.earned }"
                        :title="tOr(`history.badge.${badge.key}.description`, badge.description)"
                    >
                        <span class="bf-row-title text-[16px]">{{ tOr(`history.badge.${badge.key}.label`, badge.label) }}</span>
                        <span class="bf-row-sub">
                            {{ tOr(`history.badge.${badge.key}.description`, badge.description) }}<template v-if="!badge.earned"> ({{ t('history.games.badgeLocked') }})</template>
                        </span>
                    </li>
                </ul>
            </section>

            <section class="bf-list" :aria-label="t('history.games.sizes')">
                <h2 class="bf-row-sub m-0 mt-1">{{ t('history.games.sizes') }}</h2>
                <div v-for="tier in tiers" :key="tier.difficulty" class="bf-row" :class="{ 'is-dashed': tier.solvedCount === 0 }">
                    <span class="bf-row-text">
                        <span class="bf-row-title">{{ tierName(tier) }}</span>
                        <span v-if="tier.solvedCount > 0" class="bf-row-sub">{{ t('history.games.solvedCount', { count: tier.solvedCount }) }}</span>
                    </span>
                    <span v-if="tier.timed && tier.bestTimeMs != null" class="bf-history-time">{{ fmtClock(tier.bestTimeMs) }}</span>
                    <span v-else-if="tier.solvedCount === 0" class="bf-row-sub shrink-0">{{ t('history.games.notYet') }}</span>
                </div>
            </section>

            <Link href="/game/replays" class="bf-row">
                <span class="bf-row-text"><span class="bf-row-title">{{ t('history.games.toReplays') }}</span></span>
                <RowArrow />
            </Link>
        </main>
    </div>
</template>
