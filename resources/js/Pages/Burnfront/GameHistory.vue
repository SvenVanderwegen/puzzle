<script setup>
import { Head } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';

defineProps({
    tiers: { type: Array, default: () => [] }, // {difficulty, label, timed, solvedCount, bestTimeMs}
});
</script>

<template>
    <Head title="Game History — Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-6 pb-16">
        <SiteBar :back="{ href: '/account', text: 'Account' }" />

        <header class="flex flex-col gap-2">
            <p class="text-[11px] tracking-[.22em] text-ash-dim uppercase">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-paper">GAME HISTORY</h1>
            <p class="max-w-[52ch] text-ash">Every endless tier you&rsquo;ve closed at least once, and your fastest verified board for it.</p>
        </header>

        <section aria-label="Endless tier records" class="flex flex-col gap-3">
            <div v-for="tier in tiers" :key="tier.difficulty" class="flex flex-col gap-1 rounded-md border border-line px-5 py-4">
                <span class="bf-tile-title">{{ tier.label }}</span>
                <span v-if="tier.solvedCount > 0" class="bf-tile-meta">
                    {{ tier.solvedCount }} closed
                    <template v-if="tier.timed && tier.bestTimeMs != null"> · best {{ fmtClock(tier.bestTimeMs) }}</template>
                </span>
                <span v-else class="bf-tile-meta">Not yet attempted</span>
            </div>
        </section>
    </main>
</template>
