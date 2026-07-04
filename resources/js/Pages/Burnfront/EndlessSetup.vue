<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    difficulties: { type: Object, default: () => ({}) },
});

function meta(config) {
    const size = `${config.rows}×${config.cols} grid · ${config.breaks} firebreaks`;
    return config.timed ? size : `${size} · untimed`;
}
</script>

<template>
    <Head title="Endless · Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-10 pb-16">
        <p class="text-[11px] tracking-[.22em] text-ash-dim uppercase">
            <Link href="/" class="text-ash-dim hover:text-ember">&larr; Menu</Link>
        </p>

        <header class="flex flex-col gap-2">
            <h1 class="font-staatliches text-[clamp(40px,9vw,56px)] leading-[0.95] font-normal tracking-[.035em] text-paper">
                CHOOSE A DIFFICULTY
            </h1>
            <p class="max-w-[52ch] text-ash">Pick a tier to generate a fresh incident. You can change difficulty any time from the board.</p>
        </header>

        <nav class="flex flex-col gap-3" aria-label="Difficulty tiers">
            <Link v-for="(config, key) in difficulties" :key="key" :href="`/endless/play?difficulty=${key}`" class="bf-tile">
                <span class="bf-tile-title">{{ config.label }}</span>
                <span class="bf-tile-meta">{{ meta(config) }}</span>
            </Link>

            <div class="bf-tile is-locked pointer-events-none cursor-default" aria-disabled="true">
                <span class="bf-tile-title">Custom</span>
                <span class="bf-tile-desc">Set your own grid size and firebreak count.</span>
                <span class="bf-tile-meta">Coming soon</span>
            </div>
        </nav>
    </main>
</template>
