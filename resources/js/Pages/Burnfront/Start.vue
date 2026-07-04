<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import HowItWorksDemo from './HowItWorksDemo.vue';
import { fmtClock } from '@/lib/burnfront-engine';

const props = defineProps({
    dailyStatus: { type: Object, default: null }, // {alreadyScored, scoreTimeMs} | null, signed-in users only
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);

const dailyMeta = computed(() => {
    if (!currentUser.value) return 'Sign in to unlock';
    if (props.dailyStatus?.alreadyScored) return `Solved in ${fmtClock(props.dailyStatus.scoreTimeMs)}`;
    return "Today's incident awaits";
});
</script>

<template>
    <Head title="Burnfront" />

    <main class="mx-auto flex max-w-[900px] flex-col gap-8 px-4 pt-10 pb-16">
        <header class="flex flex-col gap-2">
            <div class="flex items-start justify-between gap-3">
                <p class="text-[11px] tracking-[.22em] text-ash-dim uppercase">Incident report &middot; deduction puzzle</p>
                <p class="text-[11px] whitespace-nowrap text-ash-dim">
                    <template v-if="currentUser">
                        Signed in as {{ currentUser.name }} ·
                        <Link href="/logout" method="post" as="button" class="cursor-pointer text-ember hover:text-flame">Log out</Link>
                    </template>
                    <template v-else>
                        <Link href="/login" class="text-ember hover:text-flame">Sign in</Link>
                        ·
                        <Link href="/register" class="text-ember hover:text-flame">Register</Link>
                    </template>
                </p>
            </div>
            <h1 class="font-staatliches text-[clamp(52px,11vw,76px)] leading-[0.95] font-normal tracking-[.035em] text-paper text-balance">
                BURNFRONT<span class="text-flame" style="text-shadow: 0 0 18px rgba(255, 216, 107, 0.45)">★</span>
            </h1>
            <p class="mt-0.5 max-w-[52ch] text-ash">
                The fire is out. The report says when it reached each numbered cell. Reconstruct the firebreaks that shaped its
                path — there is exactly one way, and pure logic finds it.
            </p>
        </header>

        <section class="grid gap-7 md:grid-cols-[minmax(0,1fr)_320px]" aria-label="Start">
            <div aria-label="Preview">
                <HowItWorksDemo />
            </div>

            <nav class="flex flex-col gap-3" aria-label="Game modes">
                <Link v-if="currentUser" href="/daily/play" class="bf-tile">
                    <span class="bf-tile-title">Daily Puzzle</span>
                    <span class="bf-tile-desc">One shared incident a day. Race the clock and climb today&rsquo;s board.</span>
                    <span class="bf-tile-meta" :class="{ 'is-solved': dailyStatus?.alreadyScored }">{{ dailyMeta }}</span>
                </Link>
                <Link v-else href="/login" class="bf-tile is-locked">
                    <span class="bf-tile-title">Daily Puzzle</span>
                    <span class="bf-tile-desc">One shared incident a day. Race the clock and climb today&rsquo;s board.</span>
                    <span class="bf-tile-meta">&#128274; {{ dailyMeta }}</span>
                </Link>

                <Link href="/endless" class="bf-tile">
                    <span class="bf-tile-title">Endless</span>
                    <span class="bf-tile-desc">Pick a difficulty and generate as many fires as you like.</span>
                    <span class="bf-tile-meta">5 difficulty tiers</span>
                </Link>

                <Link href="/how-to" class="bf-tile">
                    <span class="bf-tile-title">How To Play</span>
                    <span class="bf-tile-desc">An interactive walkthrough of the rules, beat by beat.</span>
                    <span class="bf-tile-meta">2 minute read</span>
                </Link>
            </nav>
        </section>

        <footer>
            <p class="max-w-[58ch] text-[12.5px] text-ash-dim">
                Every fire is generated on the Burnfront incident desk and machine-verified: exactly one valid placement of
                breaks, a solving path that needs no guessing, and no firebreak the clues can&rsquo;t justify.
            </p>
        </footer>
    </main>
</template>
