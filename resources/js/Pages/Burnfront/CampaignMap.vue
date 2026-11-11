<script setup>
/* The campaign: the player's own ladder. Every solved fire gives points,
   points raise the level, and every few levels start a new rank with a
   harder kind of fire. The top card says where you stand and has the one
   button that matters; below it the ranks, each in its own step of the heat
   ramp (yellow for the first rank, violet for the last, so colour = how far
   along you are), then a plain list of how points are earned.

   Data: standing (Progression::standing()), ranks and next
   (CampaignController::map()), rules (Progression::rules()). */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import RankBadge from '@/Components/Progress/RankBadge.vue';
import RowArrow from '@/Components/RowArrow.vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';
import { heatCell } from '@/lib/heat';
import { isDark } from '@/lib/theme';
import { DEFAULT_XP_RULES, fireXp, rankStep } from '@/lib/xp';

const props = defineProps({
    standing: { type: Object, required: true }, // {xp, level, intoLevel, levelSize, toGo, rank, rankKey, nextRankLevel}
    ranks: { type: Array, default: () => [] }, // [{index, key, fromLevel, toLevel, state: 'done'|'now'|'later', levels: [{level, state}]}]
    next: { type: Object, default: null }, // NextFire::for(): the fire the button opens
    rules: { type: Object, default: null },
});

const xpRules = computed(() => props.rules ?? DEFAULT_XP_RULES);
const pct = computed(() => Math.min(100, Math.round((props.standing.intoLevel / Math.max(1, props.standing.levelSize)) * 100)));
const rankName = (key) => t(`progress.rank.${key}`);

function tileStyle(rank, node) {
    if (node.state !== 'reached') return null;
    const c = heatCell(rankStep(rank.index, xpRules.value), 1, isDark.value);
    return { background: c.bg, color: c.fg };
}

function tileLabel(node) {
    if (node.state === 'reached') return t('campaign.levelDone', { n: node.level });
    if (node.state === 'current') return t('campaign.levelNow', { n: node.level });
    return t('campaign.levelLocked', { n: node.level });
}

function rankLevels(rank) {
    return rank.toLevel == null ? t('campaign.rankLevelsOpen', { from: rank.fromLevel }) : t('campaign.rankLevels', { from: rank.fromLevel, to: rank.toLevel });
}

const statusKey = { done: 'rankDone', now: 'rankNow', later: 'rankLater' };

/* How points are earned, straight from the rules the server uses. */
const how = computed(() => {
    const r = xpRules.value;
    return [
        { key: 'fire', value: t('campaign.how.fireValue', { min: fireXp(5, 5, r), max: fireXp(8, 8, r) }) },
        { key: 'noHints', value: `+${r.noHints}` },
        { key: 'daily', value: `+${r.daily}` },
        { key: 'streak', value: t('campaign.how.streakValue', { max: `+${r.streakPerDay * r.streakMaxDays}` }) },
        { key: 'week', value: `+${r.weekGoal}` },
    ];
});
</script>

<template>
    <Head :title="t('campaign.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/', text: t('common.home') }" />

        <main class="flex flex-col gap-4">
            <header class="bf-page-head pb-1">
                <p class="bf-eyebrow">{{ t('campaign.eyebrow') }}</p>
                <h1 class="bf-page-title">{{ t('campaign.title') }}</h1>
                <p class="bf-lede">{{ t('campaign.lead') }}</p>
            </header>

            <section class="bf-card flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <RankBadge :rank="standing.rank" :rules="xpRules" :size="56" />
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="bf-camp-level">{{ t('campaign.level', { level: standing.level }) }}</span>
                        <span class="bf-row-sub">{{ rankName(standing.rankKey) }}</span>
                    </div>
                    <span class="bf-mono-label tabular-nums">{{ t('campaign.points', { xp: standing.xp }) }}</span>
                </div>
                <span class="bf-progress is-tall" aria-hidden="true"><span :style="{ width: pct + '%' }"></span></span>
                <p class="bf-row-sub m-0">{{ t('campaign.toNext', { toGo: standing.toGo, level: standing.level + 1 }) }}</p>
                <Link href="/campaign/play" class="bf-btn bf-btn-primary bf-btn-stack mt-1 w-full">
                    <span>{{ t('campaign.playNext') }}</span>
                    <small v-if="next?.kind === 'campaign'">{{ t('campaign.playNextDetail', { level: next.level, rows: next.rows, cols: next.cols }) }}</small>
                </Link>
            </section>

            <h2 class="bf-row-sub m-0 mt-1">{{ t('campaign.ranksTitle') }}</h2>

            <section v-for="rank in ranks" :key="rank.key" class="bf-camp-part" :class="`is-${rank.state}`">
                <header class="bf-camp-rank-head">
                    <RankBadge :rank="rank.index" :rules="xpRules" :size="44" :muted="rank.state === 'later'" />
                    <div class="flex min-w-0 flex-1 flex-col">
                        <h3 class="bf-row-title m-0">{{ rankName(rank.key) }}</h3>
                        <span class="bf-row-sub">{{ rankLevels(rank) }} · {{ t(`campaign.what.${rank.key}`) }}</span>
                    </div>
                    <!-- a rank not reached yet says so with its grey badge and locks; the words would only crowd the line -->
                    <span class="bf-camp-part-status" :class="{ 'sr-only': rank.state === 'later' }">{{ t(`campaign.${statusKey[rank.state]}`) }}</span>
                </header>
                <ol v-if="rank.levels.length" class="bf-camp-tiles">
                    <li v-for="node in rank.levels" :key="node.level">
                        <Link v-if="node.state === 'current'" href="/campaign/play" class="bf-camp-tile is-current" :aria-label="tileLabel(node)">
                            <span class="bf-camp-num">{{ node.level }}</span>
                            <span class="bf-camp-tag">{{ t('campaign.play') }}<RowArrow /></span>
                        </Link>
                        <span
                            v-else
                            class="bf-camp-tile"
                            :class="node.state === 'reached' ? 'is-done' : 'is-locked'"
                            :style="tileStyle(rank, node)"
                            role="img"
                            :aria-label="tileLabel(node)"
                        >
                            <span class="bf-camp-num">{{ node.level }}</span>
                            <svg v-if="node.state === 'reached'" class="bf-camp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                            <svg v-else class="bf-camp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                        </span>
                    </li>
                </ol>
                <Link v-else-if="rank.state === 'now'" href="/campaign/play" class="bf-camp-tile is-current is-wide" :aria-label="tileLabel({ state: 'current', level: standing.level })">
                    <span class="bf-camp-num">{{ standing.level }}</span>
                    <span class="bf-camp-tag">{{ t('campaign.play') }}<RowArrow /></span>
                </Link>
            </section>

            <section class="bf-card flex flex-col gap-2" aria-labelledby="how-title">
                <h2 id="how-title" class="bf-row-title m-0">{{ t('campaign.how.title') }}</h2>
                <p class="bf-row-sub m-0">{{ t('campaign.how.lead') }}</p>
                <ul class="bf-camp-how">
                    <li v-for="line in how" :key="line.key">
                        <span>{{ t(`campaign.how.${line.key}`) }}</span>
                        <b>{{ line.value }}</b>
                    </li>
                </ul>
            </section>
        </main>
    </div>
</template>
