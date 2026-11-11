<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import RowArrow from '@/Components/RowArrow.vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';
import { plural, tOr } from '@/lib/plural';

defineProps({
    plays: { type: Array, default: () => [] }, // {id, mode, difficulty, difficultyLabel, date, rows, cols, breaks, timeMs, hintsUsed, moveCount, playedAt}, most recent first
});

function title(play) {
    if (play.mode === 'daily') return t('history.replays.daily');
    if (play.mode === 'campaign') return tOr('common.difficulty.campaign', 'Campaign');
    return play.difficulty ? tOr(`common.difficulty.${play.difficulty}`, play.difficultyLabel || t('history.replays.practice')) : t('history.replays.practice');
}

// The daily fire's own date when there is one, else the day it was played.
function subline(play) {
    const dims = t('common.by', { rows: play.rows, cols: play.cols });
    const raw = play.mode === 'daily' && play.date ? new Date(`${play.date}T12:00:00`) : new Date(play.playedAt);
    const when = raw.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
    return `${dims} · ${when}`;
}
</script>

<template>
    <Head :title="t('history.replays.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/account', text: t('history.back') }" />

        <main class="flex flex-col gap-4">
            <header class="bf-page-head pb-1">
                <p class="bf-eyebrow">{{ t('history.replays.eyebrow') }}</p>
                <h1 class="bf-page-title">{{ t('history.replays.title') }}</h1>
                <p class="bf-lede">{{ t('history.replays.lead') }}</p>
            </header>

            <section v-if="plays.length" class="bf-list" :aria-label="t('history.replays.eyebrow')">
                <Link v-for="play in plays" :key="play.id" :href="`/game/replays/${play.id}`" class="bf-row">
                    <span class="bf-row-text">
                        <span class="bf-row-title">{{ title(play) }}</span>
                        <span class="bf-row-sub">{{ subline(play) }}</span>
                        <span class="bf-row-sub">
                            {{ plural('history.replays.moves', play.moveCount ?? 0) }} · {{ plural('history.hints', play.hintsUsed ?? 0) }}
                        </span>
                    </span>
                    <span v-if="play.timeMs != null" class="bf-history-time">{{ fmtClock(play.timeMs) }}</span>
                    <RowArrow />
                </Link>
            </section>
            <p v-else class="bf-lede">{{ t('history.replays.empty') }}</p>
        </main>
    </div>
</template>
