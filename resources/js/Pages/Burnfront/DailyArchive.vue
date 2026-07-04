<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';

defineProps({
    entries: { type: Array, required: true }, // [{date, solved, timeMs}], most recent first
});
</script>

<template>
    <Head title="Daily Archive — Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-6 pb-16">
        <SiteBar :back="{ href: '/daily/history', text: 'Daily history' }" />

        <header class="flex flex-col gap-2">
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-paper">DAILY ARCHIVE</h1>
            <p class="max-w-[52ch] text-sm text-ash">
                Past incidents, reopened for practice. These runs are never scored or added to a leaderboard.
            </p>
        </header>

        <ol class="flex flex-col gap-1.5" aria-label="Past daily incidents">
            <li v-for="entry in entries" :key="entry.date">
                <Link :href="`/daily/archive/${entry.date}/play`" class="bf-tile flex-row items-center justify-between py-3">
                    <span class="bf-tile-title text-[16px]">{{ entry.date }}</span>
                    <span class="bf-tile-meta" :class="{ 'is-solved': entry.solved }">
                        {{ entry.solved ? `Solved in ${fmtClock(entry.timeMs)}` : 'Not yet solved' }}
                    </span>
                </Link>
            </li>
        </ol>
    </main>
</template>
