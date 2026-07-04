<script setup>
import { Head, Link } from '@inertiajs/vue3';
import SiteBar from '@/Components/SiteBar.vue';

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

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-6 pb-12 sm:pt-10 sm:pb-16">
        <SiteBar :back="{ href: '/', text: 'Menu' }" />

        <header class="flex flex-col gap-2">
            <h1 class="font-staatliches text-[clamp(40px,9vw,56px)] leading-[0.95] font-normal tracking-[.035em] text-paper">
                CHOOSE A DIFFICULTY
            </h1>
            <p class="max-w-[52ch] text-ash">Pick a tier to generate a fresh incident. Come back here any time to switch tiers.</p>
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
