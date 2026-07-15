<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';
import LookoutHero from '@/Components/LookoutHero.vue';
import ModeGlyph from '@/Components/ModeGlyph.vue';
import FirstRunBriefing from '@/Components/FirstRunBriefing.vue';
import { hasCompletedOnboarding } from '@/lib/burnfront-onboarding';

const props = defineProps({
    dailyStatus: { type: Object, default: null },
    campaignStatus: { type: Object, default: null },
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);
const firstRunVisible = ref(!hasCompletedOnboarding());

const streakSuffix = computed(() => {
    const current = props.dailyStatus?.streak?.current ?? 0;
    return current > 0 ? ` · ${current} day streak` : '';
});

const dailyMeta = computed(() => {
    if (!currentUser.value) return 'Account required';
    if (props.dailyStatus?.alreadyScored) return `Filed in ${fmtClock(props.dailyStatus.scoreTimeMs)}${streakSuffix.value}`;
    return `New incident available${streakSuffix.value}`;
});

const campaignMeta = computed(() => {
    if (!currentUser.value) return 'Account required';
    const c = props.campaignStatus;
    if (!c) return 'Case 1 of 20 · Lookout';
    if (c.maxed) return `Case ${c.level} of 20 · record closed`;
    return `Case ${c.level} of 20 · ${c.chapterLabel}`;
});

const campaignXpPct = computed(() => {
    const c = props.campaignStatus;
    if (!c || c.maxed) return 100;
    return Math.min(100, Math.round((c.xpIntoLevel / c.xpToNextLevel) * 100));
});

const modes = computed(() => {
    const signedIn = Boolean(currentUser.value);
    const entries = [
        {
            key: 'daily',
            eyebrow: 'Shared dispatch',
            title: 'Daily Incident',
            description: 'Solve today\'s shared timed incident and compare your result.',
            meta: dailyMeta.value,
            href: signedIn ? '/daily/play' : '/login',
            locked: !signedIn,
            featured: signedIn && !props.dailyStatus?.alreadyScored,
            action: signedIn ? 'Open incident' : 'Sign in',
        },
        {
            key: 'campaign',
            eyebrow: 'Career file',
            title: 'Campaign',
            description: 'Rise through five districts and close all 20 case files.',
            meta: campaignMeta.value,
            href: signedIn ? '/campaign' : '/login',
            locked: !signedIn,
            featured: signedIn && Boolean(props.dailyStatus?.alreadyScored),
            action: signedIn ? 'Continue case' : 'Sign in',
        },
        {
            key: 'endless',
            eyebrow: 'Open assignment',
            title: 'Endless Desk',
            description: 'Choose a difficulty and generate a fresh incident.',
            meta: '5 ranks · custom grids',
            href: '/endless',
            locked: false,
            featured: !signedIn,
            action: 'Choose rank',
        },
        {
            key: 'howto',
            eyebrow: 'Training',
            title: 'Field Manual',
            description: 'Learn the evidence rules in five guided, player-controlled steps.',
            meta: '5 guided steps',
            href: '/how-to',
            locked: false,
            action: 'Begin training',
        },
    ];

    return entries.sort((a, b) => Number(b.featured) - Number(a.featured));
});

const visibleModes = computed(() =>
    firstRunVisible.value ? modes.value.filter((mode) => mode.key !== 'howto') : modes.value
);
</script>

<template>
    <Head title="Burnfront" />

    <main class="mx-auto flex max-w-[1040px] flex-col gap-5 px-3 pt-3 pb-10 sm:gap-6 sm:px-5 sm:pt-5 sm:pb-14">
        <SiteBar :label="currentUser ? 'Operations · signed in' : 'Operations'" />

        <LookoutHero />

        <FirstRunBriefing @visibility="firstRunVisible = $event" />

        <section class="bf-desk-heading" aria-labelledby="assignments-title">
            <div>
                <p class="bf-eyebrow">Incident desk</p>
                <h2 id="assignments-title">Select assignment</h2>
            </div>
            <div class="flex flex-col items-start gap-1 sm:items-end">
                <p>{{ currentUser ? 'Your active files and field records.' : 'Endless and training files are open without an account.' }}</p>
                <Link v-if="firstRunVisible" href="/how-to" class="bf-desk-help">Open field manual &rarr;</Link>
            </div>
        </section>

        <nav class="bf-mode-grid" :class="{ 'is-briefing': firstRunVisible }" aria-label="Game modes">
            <Link
                v-for="mode in visibleModes"
                :key="mode.key"
                :href="mode.href"
                class="bf-mode-card"
                :class="[`is-${mode.key}`, { 'is-locked': mode.locked, 'is-featured': mode.featured }]"
            >
                <span class="bf-mode-visual" aria-hidden="true"><ModeGlyph :mode="mode.key" /></span>

                <span class="bf-mode-body">
                    <span class="bf-mode-file">{{ mode.eyebrow }} <template v-if="mode.featured">&middot; Recommended</template></span>
                    <span class="bf-mode-title">{{ mode.title }}</span>
                    <span class="bf-mode-desc">{{ mode.description }}</span>
                    <span class="bf-mode-meta" :class="{ 'is-solved': mode.key === 'daily' && dailyStatus?.alreadyScored }">
                        {{ mode.meta }}
                    </span>
                    <span v-if="mode.key === 'campaign' && currentUser" class="bf-xp-track mt-1">
                        <span class="bf-xp-fill" :style="{ width: campaignXpPct + '%' }"></span>
                    </span>
                </span>

                <span v-if="mode.locked" class="bf-mode-lock">Sign-in required</span>
                <span class="bf-mode-action">{{ mode.action }} <b aria-hidden="true">&rarr;</b></span>
            </Link>
        </nav>

        <footer class="bf-system-footer">
            <span><i aria-hidden="true"></i> Incident generator online</span>
            <span>LVU standard 04-B</span>
        </footer>
    </main>
</template>
