<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';
import RubberStamp from '@/Components/RubberStamp.vue';
import LookoutHero from '@/Components/LookoutHero.vue';

const props = defineProps({
    dailyStatus: { type: Object, default: null }, // {alreadyScored, scoreTimeMs} | null, signed-in users only
    campaignStatus: { type: Object, default: null }, // {level, chapterLabel, xpIntoLevel, xpToNextLevel, maxed} | null, signed-in users only
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);

const streakSuffix = computed(() => {
    const current = props.dailyStatus?.streak?.current ?? 0;
    return current > 0 ? ` · streak ${current}` : '';
});

const dailyMeta = computed(() => {
    if (!currentUser.value) return 'Sign in to unlock';
    if (props.dailyStatus?.alreadyScored) return `Solved ${fmtClock(props.dailyStatus.scoreTimeMs)}${streakSuffix.value}`;
    return `Today's incident awaits${streakSuffix.value}`;
});

const campaignMeta = computed(() => {
    if (!currentUser.value) return 'Sign in to unlock';
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
</script>

<template>
    <Head title="Burnfront" />

    <main class="mx-auto flex max-w-[900px] flex-col gap-7 px-4 pt-6 pb-12 sm:gap-8 sm:pt-10 sm:pb-16">
        <SiteBar :label="currentUser ? 'Case index · signed in' : 'Case index'" />

        <header class="flex flex-col gap-3.5">
            <div class="relative overflow-hidden rounded-lg border border-rule">
                <LookoutHero class="h-[210px] sm:h-[260px]" />
                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3 font-mono text-[10px] tracking-[.12em] text-stock uppercase [text-shadow:0_1px_3px_#000]">
                    <div class="flex items-start justify-between">
                        <span>Ridgeline lookout</span>
                        <span class="text-ember-hi">Sector 7</span>
                    </div>
                    <div class="flex items-end justify-between text-ash">
                        <span>Watch active · Live</span>
                        <span>Visibility 12 mi</span>
                    </div>
                </div>
            </div>

            <h1 class="flex items-center gap-1 font-staatliches text-[clamp(44px,13vw,72px)] leading-[0.86] font-normal tracking-[.02em] text-stock text-balance">
                BURNFRONT<FlameGlyph glow class="h-[.78em] w-[.62em]" />
            </h1>
            <p class="max-w-[52ch] text-ash">
                The fire is out. The report says when it reached each numbered cell. Reconstruct the breaks — there is
                exactly one way.
            </p>
        </header>

        <nav class="grid gap-3 sm:grid-cols-2" aria-label="Game modes">
            <Link v-if="currentUser" href="/daily/play" class="bf-tile">
                <span class="bf-tile-tab">File 01</span>
                <span class="bf-tile-card">
                    <span class="bf-tile-title">Daily Puzzle</span>
                    <span class="bf-tile-desc">One shared incident a day. Race the clock and climb today&rsquo;s board.</span>
                    <span class="bf-tile-meta" :class="{ 'is-solved': dailyStatus?.alreadyScored }">{{ dailyMeta }}</span>
                </span>
            </Link>
            <Link v-else href="/login" class="bf-tile is-locked">
                <span class="bf-tile-tab">File 01</span>
                <RubberStamp tone="void" size="sm" class="bf-tile-sealed">Sealed</RubberStamp>
                <span class="bf-tile-card">
                    <span class="bf-tile-title">Daily Puzzle</span>
                    <span class="bf-tile-desc">One shared incident a day. Race the clock and climb today&rsquo;s board.</span>
                    <span class="bf-tile-meta">Sign in to unlock</span>
                </span>
            </Link>

            <Link v-if="currentUser" href="/campaign" class="bf-tile">
                <span class="bf-tile-tab">File 02</span>
                <span class="bf-tile-card">
                    <span class="bf-tile-title">Campaign</span>
                    <span class="bf-tile-desc">20 incidents across 5 case files, hardest fires last.</span>
                    <span class="bf-tile-meta">{{ campaignMeta }}</span>
                    <div class="bf-xp-track mt-1"><div class="bf-xp-fill" :style="{ width: campaignXpPct + '%' }"></div></div>
                </span>
            </Link>
            <Link v-else href="/login" class="bf-tile is-locked">
                <span class="bf-tile-tab">File 02</span>
                <RubberStamp tone="void" size="sm" class="bf-tile-sealed">Sealed</RubberStamp>
                <span class="bf-tile-card">
                    <span class="bf-tile-title">Campaign</span>
                    <span class="bf-tile-desc">20 incidents across 5 case files, hardest fires last.</span>
                    <span class="bf-tile-meta">Sign in to unlock</span>
                </span>
            </Link>

            <Link href="/endless" class="bf-tile">
                <span class="bf-tile-tab">File 03</span>
                <span class="bf-tile-card">
                    <span class="bf-tile-title">Endless</span>
                    <span class="bf-tile-desc">Pick a difficulty and generate as many fires as you like.</span>
                    <span class="bf-tile-meta">5 tiers, plus a custom grid</span>
                </span>
            </Link>

            <Link href="/how-to" class="bf-tile">
                <span class="bf-tile-tab">File 04</span>
                <span class="bf-tile-card">
                    <span class="bf-tile-title">How To</span>
                    <span class="bf-tile-desc">An interactive walkthrough of the rules, beat by beat.</span>
                    <span class="bf-tile-meta">2 minute read</span>
                </span>
            </Link>
        </nav>

        <footer>
            <p class="max-w-[58ch] text-[12.5px] text-ash-dim">
                Every fire is generated on the Burnfront incident desk and machine-verified: exactly one valid placement of
                breaks, a solving path that needs no guessing, and no firebreak the clues can&rsquo;t justify.
            </p>
        </footer>
    </main>
</template>
