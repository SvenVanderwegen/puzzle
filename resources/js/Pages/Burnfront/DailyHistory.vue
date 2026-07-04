<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';

defineProps({
    entries: { type: Array, default: () => [] }, // {date, name, blurb, time_ms, hints_used}, most recent first
    streak: { type: Object, default: () => ({ current: 0, best: 0 }) },
});
</script>

<template>
    <Head title="Daily History — Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-6 pb-16">
        <SiteBar :back="{ href: '/account', text: 'Account' }" />

        <header class="flex flex-col gap-2">
            <p class="text-[11px] tracking-[.22em] text-ash-dim uppercase">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-paper">DAILY HISTORY</h1>
        </header>

        <div class="flex gap-3">
            <div class="flex flex-1 flex-col gap-1 rounded-md border border-line p-3.5">
                <span class="text-[11px] tracking-[.14em] text-ash-dim uppercase">Current streak</span>
                <span class="font-staatliches text-3xl text-paper">{{ streak.current }}</span>
            </div>
            <div class="flex flex-1 flex-col gap-1 rounded-md border border-line p-3.5">
                <span class="text-[11px] tracking-[.14em] text-ash-dim uppercase">Best streak</span>
                <span class="font-staatliches text-3xl text-paper">{{ streak.best }}</span>
            </div>
        </div>

        <section v-if="entries.length" aria-label="Past daily incidents" class="flex flex-col gap-3">
            <Link
                v-for="entry in entries"
                :key="entry.date"
                :href="`/daily/history/play?date=${entry.date}`"
                class="bf-tile"
            >
                <span class="bf-tile-title">{{ entry.name || 'Unnamed incident' }}</span>
                <span class="bf-tile-desc">{{ entry.date }}<template v-if="entry.blurb"> — {{ entry.blurb }}</template></span>
                <span class="bf-tile-meta">
                    {{ fmtClock(entry.time_ms) }}
                    <template v-if="entry.hints_used === 0"> · clean</template>
                    <template v-else> · {{ entry.hints_used }} hint{{ entry.hints_used === 1 ? '' : 's' }}</template>
                </span>
            </Link>
        </section>
        <p v-else class="text-sm text-ash">No closed cases yet — solve today&rsquo;s daily incident to start your record.</p>
    </main>
</template>
