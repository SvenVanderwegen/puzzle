<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';

const props = defineProps({
    totalClosed: { type: Number, required: true },
    bestTimeMs: { type: Number, default: null },
    averageTimeMs: { type: Number, default: null },
    cleanCount: { type: Number, required: true },
    streak: { type: Object, required: true }, // {current, best}
    entries: { type: Array, required: true }, // [{date, timeMs, clean}]
});
</script>

<template>
    <Head title="Daily History — Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-6 pb-16">
        <SiteBar :back="{ href: '/account', text: 'Account' }" />

        <header class="flex flex-col gap-2">
            <p class="text-[11px] tracking-[.22em] text-ash-dim uppercase">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-paper">DAILY HISTORY</h1>
            <p class="max-w-[52ch] text-sm text-ash">Every daily incident this account has closed, and the streak behind it.</p>
        </header>

        <section v-if="totalClosed === 0" class="rounded-md border border-line px-5 py-4 text-sm text-ash">
            No incidents closed yet.
            <Link href="/daily/play" class="text-ember hover:text-flame">Play today&rsquo;s daily</Link>
            to start a record.
        </section>

        <template v-else>
            <section class="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Daily stats">
                <div class="bf-chip flex-col items-start gap-0.5 py-3">
                    <span class="bf-chip-key">Closed</span>
                    <span class="bf-chip-value text-lg">{{ totalClosed }}</span>
                </div>
                <div class="bf-chip flex-col items-start gap-0.5 py-3">
                    <span class="bf-chip-key">Streak</span>
                    <span class="bf-chip-value text-lg">{{ streak.current }}<span class="text-ash-dim"> / {{ streak.best }} best</span></span>
                </div>
                <div class="bf-chip flex-col items-start gap-0.5 py-3">
                    <span class="bf-chip-key">Best time</span>
                    <span class="bf-chip-value text-lg">{{ bestTimeMs !== null ? fmtClock(bestTimeMs) : '—' }}</span>
                </div>
                <div class="bf-chip flex-col items-start gap-0.5 py-3">
                    <span class="bf-chip-key">Clean cases</span>
                    <span class="bf-chip-value text-lg">{{ cleanCount }}<span class="text-ash-dim"> / {{ totalClosed }}</span></span>
                </div>
            </section>

            <section class="flex flex-col gap-1.5 rounded-md border border-line p-3.5" aria-label="Closed incidents">
                <h3 class="text-[11px] tracking-[.14em] text-ash-dim uppercase">Closed incidents</h3>
                <ol class="flex flex-col gap-1 text-sm text-ash">
                    <li v-for="entry in entries" :key="entry.date" class="flex items-center justify-between gap-3 tabular-nums">
                        <span>{{ entry.date }}<span v-if="entry.clean" class="ml-2 text-[10px] tracking-[.1em] text-ember uppercase" title="Solved with no hints">clean</span></span>
                        <span class="text-paper">{{ fmtClock(entry.timeMs) }}</span>
                    </li>
                </ol>
            </section>
        </template>

        <Link href="/daily/archive" class="bf-tile">
            <span class="bf-tile-title">Daily Archive</span>
            <span class="bf-tile-desc">Replay a past incident for practice — no score, no clock pressure.</span>
        </Link>
    </main>
</template>
