<script setup>
/* The campaign: an endless row of levels, one fire each. Put a fire out and
   the next level opens. The top card has the level you are on and the one
   button that matters; below it a stretch of the row (a few levels done,
   the current one, the next ones with their board size), coloured along
   the heat ramp, so colour = how far along you are.

   Under that, the other counter: points and rank. Every fire in every mode
   gives points; points move you up the ranks, a grade at a time.

   Data: level, path and ranks (CampaignController::map()), standing
   (Progression::standing()), rules (Progression::rules()). */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import RankBadge from '@/Components/Progress/RankBadge.vue';
import RowArrow from '@/Components/RowArrow.vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';
import { heatCell } from '@/lib/heat';
import { nextRankTitle, rankTitle } from '@/lib/rank';
import { isDark } from '@/lib/theme';
import { DEFAULT_XP_RULES, fireXp } from '@/lib/xp';

const props = defineProps({
    level: { type: Number, default: 1 }, // the level this account is on
    path: { type: Array, default: () => [] }, // [{level, state: 'done'|'current'|'locked', rows, cols}]
    endlessFrom: { type: Number, default: 77 }, // the level where the way up ends
    standing: { type: Object, required: true }, // {xp, level (points step), intoLevel, levelSize, toGo, rank, rankKey, grade}
    ranks: { type: Array, default: () => [] }, // [{index, key, fromXp, state: 'done'|'now'|'later'}]
    next: { type: Object, default: null }, // NextFire::for(): the fire the button opens
    rules: { type: Object, default: null },
});

const xpRules = computed(() => props.rules ?? DEFAULT_XP_RULES);
const pct = computed(() => Math.min(100, Math.round((props.standing.intoLevel / Math.max(1, props.standing.levelSize)) * 100)));
const rankName = (key) => t(`progress.rank.${key}`);
const current = computed(() => props.path.find((node) => node.state === 'current') ?? { rows: 5, cols: 5 });

/* A done level takes its place on the heat ramp: yellow for level 1,
   violet from where the way up ends. */
function tileStyle(node) {
    if (node.state !== 'done') return null;
    const step = Math.min(1, (node.level - 1) / Math.max(1, props.endlessFrom - 1));
    const c = heatCell(step, 1, isDark.value);
    return { background: c.bg, color: c.fg };
}

function tileLabel(node) {
    if (node.state === 'done') return t('campaign.levelDone', { n: node.level });
    if (node.state === 'current') return t('campaign.levelNow', { n: node.level });
    return t('campaign.levelLocked', { n: node.level, rows: node.rows, cols: node.cols, before: node.level - 1 });
}

/* Levels never end, so the numbers get long: four digits and more are set
   smaller to stay inside the tile on a narrow phone. */
function numClass(node) {
    const digits = String(node.level).length;
    return digits >= 6 ? 'is-tiny' : digits >= 4 ? 'is-small' : null;
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
                <div class="flex flex-col">
                    <span class="bf-camp-level">{{ t('campaign.level', { level }) }}</span>
                    <span class="bf-row-sub">{{ t('campaign.board', { rows: current.rows, cols: current.cols }) }}</span>
                </div>
                <Link href="/campaign/play" class="bf-btn bf-btn-primary bf-btn-lg w-full">{{ t('campaign.playLevel', { level }) }}</Link>
            </section>

            <section class="bf-camp-part" aria-labelledby="path-title">
                <h2 id="path-title" class="bf-row-title m-0">{{ t('campaign.pathTitle') }}</h2>
                <ol class="bf-camp-tiles">
                    <li v-for="node in path" :key="node.level">
                        <Link v-if="node.state === 'current'" href="/campaign/play" class="bf-camp-tile is-current" :aria-label="tileLabel(node)">
                            <span class="bf-camp-num" :class="numClass(node)">{{ node.level }}</span>
                            <span class="bf-camp-tag">{{ t('campaign.play') }}<RowArrow /></span>
                        </Link>
                        <span
                            v-else
                            class="bf-camp-tile"
                            :class="node.state === 'done' ? 'is-done' : 'is-locked'"
                            :style="tileStyle(node)"
                            role="img"
                            :aria-label="tileLabel(node)"
                        >
                            <span class="bf-camp-num" :class="numClass(node)">{{ node.level }}</span>
                            <span class="bf-camp-foot">
                                <span class="bf-camp-size">{{ t('campaign.size', { rows: node.rows, cols: node.cols }) }}</span>
                                <svg v-if="node.state === 'done'" class="bf-camp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                                <svg v-else class="bf-camp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                            </span>
                        </span>
                    </li>
                </ol>
                <p class="bf-row-sub m-0">{{ t('campaign.pathMore') }}</p>
            </section>

            <section class="bf-card flex flex-col gap-3" aria-labelledby="rank-title">
                <h2 id="rank-title" class="bf-row-sub m-0">{{ t('campaign.rankTitle') }}</h2>
                <div class="flex items-center gap-3">
                    <RankBadge :rank="standing.rank" :rules="xpRules" :size="56" />
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="bf-camp-level">{{ rankTitle(standing.level, xpRules) }}</span>
                        <span class="bf-row-sub tabular-nums">{{ t('campaign.points', { xp: standing.xp }) }}</span>
                    </div>
                </div>
                <span class="bf-progress is-tall" aria-hidden="true"><span :style="{ width: pct + '%' }"></span></span>
                <p class="bf-row-sub m-0">{{ t('campaign.toNext', { toGo: standing.toGo, next: nextRankTitle(standing.level, xpRules) }) }}</p>

                <h3 class="bf-row-sub m-0 mt-2">{{ t('campaign.ranksTitle') }}</h3>
                <ul class="bf-camp-ranks">
                    <li v-for="rank in ranks" :key="rank.key" :class="`is-${rank.state}`">
                        <RankBadge :rank="rank.index" :rules="xpRules" :size="36" :muted="rank.state === 'later'" />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <b>{{ rankName(rank.key) }}</b>
                            <span class="bf-row-sub tabular-nums">{{ rank.fromXp > 0 ? t('campaign.rankFrom', { xp: rank.fromXp }) : t('campaign.rankStart') }}</span>
                        </span>
                        <!-- a rank not reached yet says so with its grey badge; the words would only crowd the line -->
                        <span class="bf-camp-part-status" :class="{ 'sr-only': rank.state === 'later' }">{{ t(`campaign.${statusKey[rank.state]}`) }}</span>
                    </li>
                </ul>
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
