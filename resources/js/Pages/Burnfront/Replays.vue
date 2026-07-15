<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';
import RubberStamp from '@/Components/RubberStamp.vue';

const props = defineProps({
    plays: { type: Array, default: () => [] }, // {id, mode, difficulty, difficultyLabel, date, rows, cols, breaks, timeMs, hintsUsed, moveCount, playedAt}, most recent first
});

function title(play) {
    return play.mode === 'daily' ? 'Daily Incident' : play.difficultyLabel || 'Endless';
}

// The daily incident's own calendar date when there is one (it reads the
// same either way today's board was actually played); otherwise the
// server's playedAt timestamp, localized — endless runs have no fixed date
// of their own.
function subline(play) {
    const dims = `${play.rows}×${play.cols}`;
    const when = play.mode === 'daily' && play.date ? play.date : new Date(play.playedAt).toLocaleDateString();
    return `${dims} · ${when}`;
}
</script>

<template>
    <Head title="Game Replays — Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-3 pb-16 sm:pt-5">
        <SiteBar :back="{ href: '/account', text: 'Account' }" />

        <header class="flex flex-col gap-2">
            <p class="bf-eyebrow">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-stock">Game Replays</h1>
            <p class="max-w-[52ch] text-ash">Every closed case on record, ready to step through move by move.</p>
        </header>

        <section v-if="plays.length" aria-label="Recorded games" class="rounded-lg border border-rule-2 overflow-hidden">
            <Link
                v-for="(play, i) in plays"
                :key="play.id"
                :href="`/game/replays/${play.id}`"
                class="flex items-center gap-3 px-4 py-3.5 transition-colors duration-150 hover:bg-ember/[.07] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame"
                :class="[i % 2 === 0 ? 'bg-folder' : 'bg-[#160f0c]', i < plays.length - 1 ? 'border-b border-rule' : '']"
            >
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <span class="truncate text-sm text-stock">{{ title(play) }}</span>
                    <span class="font-mono text-[12px] font-semibold tracking-[.07em] text-ash-dim uppercase">{{ subline(play) }}</span>
                    <span class="font-mono text-[12px] font-semibold tracking-[.08em] text-ash-dim uppercase">
                        {{ play.moveCount }} move{{ play.moveCount === 1 ? '' : 's' }} recorded
                        <template v-if="play.hintsUsed">&middot; {{ play.hintsUsed }} hint{{ play.hintsUsed === 1 ? '' : 's' }}</template>
                    </span>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1.5">
                    <span v-if="play.timeMs != null" class="font-mono font-bold text-stock">{{ fmtClock(play.timeMs) }}</span>
                    <RubberStamp tone="ember" size="sm">Contained</RubberStamp>
                </div>
            </Link>
        </section>
        <p v-else class="text-sm text-ash">No recorded games yet — solve a daily or endless incident to start building a replay log.</p>
    </main>
</template>
