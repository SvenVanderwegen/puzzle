<script setup>
/* Home. Today's fire first, then one clear next step. Two states:
   - before the daily: the big card is today's fire, the campaign sits right
     under it, then Quick fire;
   - after the daily: today's result shrinks to a strip, and the big card
     becomes "Up next" (the next campaign fire).
   On wide screens an intro column with the four rules sits on the left.

   Data: dailyStatus and campaignStatus come from BurnfrontController@start.
   upNext, quickFires and progress are optional (the progression work adds
   them); everything falls back to what exists today. */
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { bfs, buildAdj, fmtClock } from '@/lib/burnfront-engine';
import { heatCell } from '@/lib/heat';
import { t } from '@/lib/i18n';
import SiteBar from '@/Components/SiteBar.vue';
import MiniBoard from '@/Components/MiniBoard.vue';
import FirstRunBriefing from '@/Components/FirstRunBriefing.vue';
import { hasCompletedOnboarding } from '@/lib/burnfront-onboarding';

const props = defineProps({
    dailyStatus: { type: Object, default: null },
    campaignStatus: { type: Object, default: null },
    upNext: { type: Object, default: null },
    quickFires: { type: Array, default: null },
    progress: { type: Object, default: null },
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);
const firstRunVisible = ref(!hasCompletedOnboarding());

const TOTAL_LEVELS = 20;

/* A real, solvable 6 by 6 fire (reference/firebreak.py, seed 2) used as the
   preview when the server does not send today's own board. */
const SAMPLE_DAILY = {
    rows: 6,
    cols: 6,
    spark: 19,
    clues: [[6, 5], [9, 4], [15, 3], [17, 9], [18, 1], [27, 5], [35, 12]],
};
const SAMPLE_CAMPAIGN = { rows: 5, cols: 5, spark: 20, clues: [[4, 8], [13, 7], [23, 9]] };

const streak = computed(() => props.dailyStatus?.streak?.current ?? 0);
const dailyDone = computed(() => Boolean(props.dailyStatus?.alreadyScored));

/* ---- today's fire ---- */
const daily = computed(() => {
    const d = props.dailyStatus ?? {};
    return {
        eyebrow: d.number ? t('home.today.eyebrowNumber', { number: d.number }) : t('home.today.eyebrow'),
        name: d.name || t('home.today.fallbackName'),
        meta: t('home.today.meta', { rows: d.rows ?? 6, cols: d.cols ?? 6, breaks: d.breaks ?? 8 }),
        board: d.clues && d.spark != null ? { rows: d.rows, cols: d.cols, spark: d.spark, clues: d.clues } : SAMPLE_DAILY,
    };
});

/* The daily resets at midnight UTC (BurnfrontController uses now('UTC')). */
const now = ref(Date.now());
let tick = null;
onMounted(() => {
    tick = setInterval(() => (now.value = Date.now()), 30000);
});
onBeforeUnmount(() => clearInterval(tick));
const resetIn = computed(() => {
    const d = new Date(now.value);
    const next = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + 1);
    const mins = Math.max(0, Math.ceil((next - now.value) / 60000));
    return t('home.today.resetIn', { hours: Math.floor(mins / 60), minutes: mins % 60 });
});

const doneLine = computed(() => {
    const d = props.dailyStatus;
    if (!d) return '';
    const parts = [];
    if (d.scoreTimeMs != null) parts.push(fmtClock(d.scoreTimeMs));
    if (typeof d.hintsUsed === 'number') {
        parts.push(d.hintsUsed === 0 ? t('home.done.noHints') : d.hintsUsed === 1 ? t('home.done.oneHint') : t('home.done.hints', { count: d.hintsUsed }));
    }
    if (streak.value > 0) parts.push(t('common.streak', { count: streak.value }));
    return parts.join(' · ');
});
const doneTile = heatCell(0.3, 1);

/* Thumbnail of today's solved burn map, when the server sends the solved
   board (dailyStatus.solved = {rows, cols, spark, clues, shaded}). */
const doneThumb = computed(() => {
    const s = props.dailyStatus?.solved;
    if (!s?.shaded || s.spark == null) return null;
    const n = s.rows * s.cols;
    const shaded = new Set(s.shaded);
    const times = Array.from(bfs(n, buildAdj(s.rows, s.cols), s.spark, (i) => !shaded.has(i)));
    return { rows: s.rows, cols: s.cols, spark: s.spark, times };
});

/* ---- campaign ---- */
const campaign = computed(() => {
    const c = props.campaignStatus;
    const u = props.upNext?.kind === 'campaign' ? props.upNext : null;
    const level = u?.level ?? c?.level ?? 1;
    const total = u?.totalLevels ?? TOTAL_LEVELS;
    const maxed = Boolean(c?.maxed);
    const done = u?.solvedLevels ?? (maxed ? total : Math.max(0, level - 1));
    return {
        level,
        total,
        done,
        maxed,
        pct: Math.round((done / total) * 100),
        part: c?.chapterKey ? t(`common.chapter.${c.chapterKey}`) : t('common.chapter.1'),
        name: u?.name || t('home.next.name', { level }),
        href: u?.href ?? (currentUser.value ? '/campaign/play' : '/login'),
        mapHref: currentUser.value ? '/campaign' : '/login',
        board: u?.clues && u?.spark != null ? { rows: u.rows, cols: u.cols, spark: u.spark, clues: u.clues } : SAMPLE_CAMPAIGN,
        rows: u?.rows ?? 5,
        cols: u?.cols ?? 5,
    };
});

/* ---- quick fire ---- */
const quick = computed(
    () =>
        props.quickFires ?? [
            { key: 'lookout', rows: 5, cols: 5, href: '/endless/play?difficulty=lookout' },
            { key: 'crew', rows: 6, cols: 6, href: '/endless/play?difficulty=crew' },
            { key: 'division', rows: 8, cols: 8, href: '/endless/play?difficulty=division' },
        ],
);

const rules = [
    { key: 'one', ...heatCell(0, 1) },
    { key: 'two', ...heatCell(0.28, 1) },
    { key: 'three', ...heatCell(0.56, 1) },
    { key: 'four', ...heatCell(0.92, 1) },
];
</script>

<template>
    <Head :title="t('home.pageTitle')" />

    <div class="bf-page">
        <SiteBar :streak="streak" />

        <FirstRunBriefing class="mb-2.5" @visibility="firstRunVisible = $event" />

        <main class="bf-home">
            <!-- wide screens: what the game is, in one sentence -->
            <section class="bf-home-intro" aria-labelledby="home-intro-title">
                <h1 id="home-intro-title">{{ t('home.intro.title') }}</h1>
                <p class="bf-lede">{{ t('home.intro.lede') }}</p>
                <div>
                    <a href="#rules" class="bf-btn">{{ t('home.intro.howItWorks') }}</a>
                </div>
            </section>

            <!-- ============ before the daily: today's fire is the big card ============ -->
            <template v-if="!dailyDone">
                <section class="bf-home-hero" aria-labelledby="today-title">
                    <div class="bf-home-hero-top">
                        <MiniBoard v-bind="daily.board" />
                        <div class="bf-home-hero-copy">
                            <span class="bf-eyebrow">{{ daily.eyebrow }}</span>
                            <h2 id="today-title">{{ daily.name }}</h2>
                            <span class="bf-home-meta">{{ daily.meta }}</span>
                            <span class="bf-home-sub">{{ resetIn }}</span>
                        </div>
                    </div>
                    <Link href="/daily/play" class="bf-btn bf-btn-primary">{{ t('home.today.play') }}</Link>
                </section>

                <Link :href="campaign.mapHref" class="bf-row" :aria-label="t('home.campaign.open')">
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="bf-eyebrow">{{ currentUser ? t('home.campaign.eyebrow', { done: campaign.done, total: campaign.total }) : t('home.campaign.eyebrowGuest') }}</span>
                        <span class="bf-home-section-title">
                            <template v-if="!currentUser">{{ t('home.campaign.guest') }}</template>
                            <template v-else-if="campaign.maxed">{{ t('home.campaign.titleDone', { total: campaign.total }) }}</template>
                            <template v-else>{{ t('home.campaign.title', { level: campaign.level }) }}</template>
                        </span>
                        <span v-if="currentUser" class="bf-progress mt-1" aria-hidden="true"><span :style="{ width: campaign.pct + '%' }"></span></span>
                    </span>
                    <span class="bf-chevron" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7" /></svg>
                    </span>
                </Link>
            </template>

            <!-- ============ after the daily: a result strip, then "Up next" ============ -->
            <template v-else>
                <section class="bf-done-strip" aria-labelledby="done-title">
                    <MiniBoard v-if="doneThumb" v-bind="doneThumb" :show-numbers="false" />
                    <span
                        v-else
                        class="flex size-[60px] shrink-0 items-center justify-center rounded-[6px]"
                        :style="{ background: doneTile.bg, color: doneTile.fg }"
                        aria-hidden="true"
                    >
                        <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                    </span>
                    <div class="bf-done-strip-copy">
                        <strong id="done-title">{{ t('home.done.title') }}</strong>
                        <span class="bf-home-sub">{{ doneLine }}</span>
                    </div>
                    <Link href="/daily/play" class="bf-icon-btn bg-soft" :aria-label="t('home.done.share')" :title="t('home.done.share')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 15V3" /><path d="M8 7l4-4 4 4" /><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" />
                        </svg>
                    </Link>
                </section>

                <section class="bf-home-hero" aria-labelledby="next-title">
                    <div class="bf-home-hero-top">
                        <MiniBoard v-bind="campaign.board" />
                        <div class="bf-home-hero-copy">
                            <span class="bf-eyebrow">{{ t('home.next.eyebrow') }}</span>
                            <h2 id="next-title">{{ campaign.maxed ? t('home.next.allDone', { total: campaign.total }) : campaign.name }}</h2>
                            <span class="bf-home-meta">{{ t('home.next.meta', { rows: campaign.rows, cols: campaign.cols, level: campaign.level, total: campaign.total }) }}</span>
                            <span class="mt-1 flex flex-col gap-1">
                                <span class="bf-progress" aria-hidden="true"><span :style="{ width: campaign.pct + '%' }"></span></span>
                                <span class="bf-home-sub">{{ t('home.next.progress', { done: campaign.done, total: campaign.total }) }}</span>
                            </span>
                        </div>
                    </div>
                    <Link :href="campaign.href" class="bf-btn bf-btn-primary">
                        {{ campaign.maxed ? t('home.next.replay') : t('home.next.play', { level: campaign.level }) }}
                    </Link>
                </section>
            </template>

            <!-- ============ Quick fire: a fresh fire at once ============ -->
            <section class="bf-card flex flex-col gap-2.5" aria-labelledby="quick-title">
                <div class="flex flex-col">
                    <h2 id="quick-title" class="bf-home-section-title m-0">{{ t('home.quick.title') }}</h2>
                    <p class="bf-home-sub m-0">{{ t('home.quick.help') }}</p>
                </div>
                <div class="bf-quick">
                    <Link v-for="q in quick" :key="q.key" :href="q.href" class="bf-btn bf-btn-soft">
                        {{ t('home.quick.size', { rows: q.rows, cols: q.cols }) }}
                    </Link>
                </div>
            </section>

            <!-- progression: weekly goal and level (Components/WeeklyProgress.vue) -->

            <nav class="bf-home-more" aria-label="More ways to play">
                <Link href="/endless" class="bf-row">
                    <span class="flex min-w-0 flex-col">
                        <span class="bf-home-section-title">{{ t('home.more.practice') }}</span>
                        <span class="bf-home-sub">{{ t('home.more.practiceHelp') }}</span>
                    </span>
                </Link>
                <Link href="/how-to" class="bf-row">
                    <span class="flex min-w-0 flex-col">
                        <span class="bf-home-section-title">{{ t('home.more.learn') }}</span>
                        <span class="bf-home-sub">{{ t('home.more.learnHelp') }}</span>
                    </span>
                </Link>
                <template v-if="currentUser">
                    <Link href="/daily/history" class="bf-row">
                        <span class="flex min-w-0 flex-col">
                            <span class="bf-home-section-title">{{ t('home.more.history') }}</span>
                            <span class="bf-home-sub">{{ t('home.more.historyHelp') }}</span>
                        </span>
                    </Link>
                    <Link href="/game/history" class="bf-row">
                        <span class="flex min-w-0 flex-col">
                            <span class="bf-home-section-title">{{ t('home.more.games') }}</span>
                            <span class="bf-home-sub">{{ t('home.more.gamesHelp') }}</span>
                        </span>
                    </Link>
                </template>
            </nav>
        </main>

        <section id="rules" class="bf-rules" aria-labelledby="rules-title">
            <h2 id="rules-title">{{ t('home.rules.title') }}</h2>
            <ol>
                <li v-for="(r, k) in rules" :key="r.key">
                    <span :style="{ background: r.bg, color: r.fg }" aria-hidden="true">{{ k + 1 }}</span>
                    <p>{{ t(`home.rules.${r.key}`) }}</p>
                </li>
            </ol>
        </section>

        <footer class="bf-site-foot">
            <span>{{ t('home.foot.promise') }}</span>
            <span>{{ t('home.foot.site') }}</span>
        </footer>
    </div>
</template>
