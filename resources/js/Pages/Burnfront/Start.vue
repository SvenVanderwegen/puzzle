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
import HomeIcon from '@/Components/HomeIcon.vue';
import FirstRunBriefing from '@/Components/FirstRunBriefing.vue';
import { hasCompletedOnboarding } from '@/lib/burnfront-onboarding';
import RankBadge from '@/Components/Progress/RankBadge.vue';
import WeeklyProgress from '@/Components/Progress/WeeklyProgress.vue';
import { guestDailyFor } from '@/lib/progress';
import { upNextText } from '@/lib/next-fire';
import { nextRankTitle, rankTitle } from '@/lib/rank';

const props = defineProps({
    dailyStatus: { type: Object, default: null },
    campaignStatus: { type: Object, default: null }, // Progression::standing(): points, rank and grade; null for a guest
    upNext: { type: Object, default: null }, // NextFire::for(): what to play after the daily
    quickFires: { type: Array, default: null }, // NextFire::quickFires()
    progress: { type: Object, default: null }, // PlayerProgress::forUser(), null for a guest
    progressRules: { type: Object, default: null }, // PlayerProgress::rules()
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);
const firstRunVisible = ref(!hasCompletedOnboarding());

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
/* A guest's daily result lives in their session, which ends after a couple
   of hours; the device remembers it for the rest of the day. */
const guestDaily = ref(null);
onMounted(() => {
    if (!currentUser.value && props.dailyStatus?.date) guestDaily.value = guestDailyFor(props.dailyStatus.date);
});
const dailyDone = computed(() => Boolean(props.dailyStatus?.alreadyScored || guestDaily.value));
const dailyTimeMs = computed(() => props.dailyStatus?.scoreTimeMs ?? guestDaily.value?.timeMs ?? null);

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
    if (dailyTimeMs.value != null) parts.push(fmtClock(dailyTimeMs.value));
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
    /* Two counters: the campaign level (one fire each, from the next fire)
       and the step on the points ladder, shown as rank and grade. */
    const level = u?.level ?? 1;
    const step = c?.level ?? 1;
    const xpRules = props.progressRules?.xp;
    return {
        level,
        rank: c?.rank ?? u?.rank ?? 0,
        rankName: rankTitle(step, xpRules),
        nextRankName: nextRankTitle(step, xpRules),
        toGo: c?.toGo ?? 0,
        pct: c?.levelSize ? Math.round((c.intoLevel / c.levelSize) * 100) : 0,
        name: t('home.next.name', { level }),
        href: u?.href ?? (currentUser.value ? '/campaign/play' : '/login'),
        mapHref: currentUser.value ? '/campaign' : '/login',
        board: u?.clues && u?.spark != null ? { rows: u.rows, cols: u.cols, spark: u.spark, clues: u.clues } : SAMPLE_CAMPAIGN,
        rows: u?.rows ?? 5,
        cols: u?.cols ?? 5,
    };
});

/* A guest gets a quick fire as "Up next" instead of a sign-in wall. */
const quickNext = computed(() => (props.upNext?.kind === 'quick' ? { ...props.upNext, text: upNextText(props.upNext) } : null));

/* ---- quick fire ---- */
const quick = computed(
    () =>
        props.quickFires ?? [
            { key: 'lookout', rows: 5, cols: 5, href: '/endless/play?difficulty=lookout' },
            { key: 'crew', rows: 6, cols: 6, href: '/endless/play?difficulty=crew' },
            { key: 'division', rows: 8, cols: 8, href: '/endless/play?difficulty=division' },
        ],
);

/* Icon tiles take a heat step in page order: yellow near the top, violet
   at the bottom, like a fire burning down the page. */
const ICON_STEP = { campaign: 0.14, quick: 0.3, practice: 0.5, learn: 0.64, history: 0.8, games: 0.94 };

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
                    <RankBadge v-if="currentUser" :rank="campaign.rank" :rules="progressRules?.xp" />
                    <HomeIcon v-else name="campaign" :step="ICON_STEP.campaign" />
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="bf-eyebrow">{{ currentUser ? t('home.campaign.eyebrow', { level: campaign.level }) : t('home.campaign.eyebrowGuest') }}</span>
                        <span class="bf-home-section-title">
                            <template v-if="!currentUser">{{ t('home.campaign.guest') }}</template>
                            <template v-else>{{ t('home.campaign.title', { rank: campaign.rankName }) }}</template>
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

                <section v-if="quickNext" class="bf-home-hero" aria-labelledby="next-title">
                    <div class="bf-home-hero-copy">
                        <span class="bf-eyebrow">{{ quickNext.text.eyebrow }}</span>
                        <h2 id="next-title">{{ t('home.quick.size', { rows: quickNext.rows, cols: quickNext.cols }) }}</h2>
                        <span class="bf-home-meta">{{ quickNext.text.meta }}</span>
                    </div>
                    <Link :href="quickNext.href" class="bf-btn bf-btn-primary">{{ quickNext.text.action }}</Link>
                </section>

                <section v-else class="bf-home-hero" aria-labelledby="next-title">
                    <div class="bf-home-hero-top">
                        <MiniBoard v-bind="campaign.board" />
                        <div class="bf-home-hero-copy">
                            <span class="bf-eyebrow">{{ t('home.next.eyebrow') }}</span>
                            <h2 id="next-title">{{ campaign.name }}</h2>
                            <span class="bf-home-meta">{{ t('home.next.meta', { rows: campaign.rows, cols: campaign.cols, rank: campaign.rankName }) }}</span>
                            <span class="mt-1 flex flex-col gap-1">
                                <span class="bf-progress" aria-hidden="true"><span :style="{ width: campaign.pct + '%' }"></span></span>
                                <span class="bf-home-sub">{{ t('home.next.progress', { toGo: campaign.toGo, next: campaign.nextRankName }) }}</span>
                            </span>
                        </div>
                    </div>
                    <Link :href="campaign.href" class="bf-btn bf-btn-primary">{{ t('home.next.play') }}</Link>
                </section>
            </template>

            <!-- ============ Quick fire: a fresh fire at once ============ -->
            <section class="bf-card flex flex-col gap-2.5" aria-labelledby="quick-title">
                <div class="flex items-center gap-3.5">
                    <HomeIcon name="quick" :step="ICON_STEP.quick" />
                    <div class="flex min-w-0 flex-col">
                        <h2 id="quick-title" class="bf-home-section-title m-0">{{ t('home.quick.title') }}</h2>
                        <p class="bf-home-sub m-0">{{ t('home.quick.help') }}</p>
                    </div>
                </div>
                <div class="bf-quick">
                    <Link v-for="q in quick" :key="q.difficulty ?? q.key" :href="q.href" class="bf-btn bf-btn-soft">
                        {{ t('home.quick.size', { rows: q.rows, cols: q.cols }) }}
                    </Link>
                </div>
            </section>

            <!-- progression: weekly goal and level -->
            <WeeklyProgress :progress="progress" :rules="progressRules" />

            <nav class="bf-home-more" :aria-label="t('home.more.label')">
                <Link href="/endless" class="bf-row">
                    <HomeIcon name="practice" :step="ICON_STEP.practice" />
                    <span class="flex min-w-0 flex-col">
                        <span class="bf-home-section-title">{{ t('home.more.practice') }}</span>
                        <span class="bf-home-sub">{{ t('home.more.practiceHelp') }}</span>
                    </span>
                </Link>
                <Link href="/how-to" class="bf-row">
                    <HomeIcon name="learn" :step="ICON_STEP.learn" />
                    <span class="flex min-w-0 flex-col">
                        <span class="bf-home-section-title">{{ t('home.more.learn') }}</span>
                        <span class="bf-home-sub">{{ t('home.more.learnHelp') }}</span>
                    </span>
                </Link>
                <template v-if="currentUser">
                    <Link href="/daily/history" class="bf-row">
                        <HomeIcon name="history" :step="ICON_STEP.history" />
                        <span class="flex min-w-0 flex-col">
                            <span class="bf-home-section-title">{{ t('home.more.history') }}</span>
                            <span class="bf-home-sub">{{ t('home.more.historyHelp') }}</span>
                        </span>
                    </Link>
                    <Link href="/game/history" class="bf-row">
                        <HomeIcon name="games" :step="ICON_STEP.games" />
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
