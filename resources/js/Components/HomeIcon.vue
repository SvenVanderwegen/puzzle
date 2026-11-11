<script setup>
/* Small icons for the home screen. One simple line set on a 24 grid
   (2px stroke, round ends), kept as plain as the Steps logo. Each icon sits
   on a square tile, the same shape as a board cell, coloured with one step
   of the heat ramp. Steps go from yellow at the top of the page to violet at
   the bottom, so the page reads like a fire burning down it.

   Icons are always decoration next to a text label, so they are hidden from
   screen readers. Use `tile` = false for a bare icon in ink (small labels). */
import { computed } from 'vue';
import { heatCell } from '@/lib/heat';

const props = defineProps({
    name: { type: String, required: true },
    step: { type: Number, default: 0 }, // 0..1 along the heat ramp
    tile: { type: Boolean, default: true },
});

const tileStyle = computed(() => {
    const light = heatCell(props.step, 1);
    const dark = heatCell(props.step, 1, true);
    return { '--tile-bg': light.bg, '--tile-fg': light.fg, '--tile-bg-dark': dark.bg, '--tile-fg-dark': dark.fg };
});
</script>

<template>
    <span :class="tile ? 'bf-tile' : 'bf-glyph'" :style="tile ? tileStyle : null" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <!-- campaign: a flag -->
            <g v-if="name === 'campaign'">
                <path d="M6 21V4" />
                <path d="M6 4h12l-2.5 4.5L18 13H6" />
            </g>
            <!-- quick fire: a lightning bolt -->
            <path v-else-if="name === 'quick'" d="M13 3 5 13.5h6L10 21l8-10.5h-6L13 3Z" />
            <!-- practice: two arrows going round -->
            <g v-else-if="name === 'practice'">
                <path d="M4 11V9.5A3.5 3.5 0 0 1 7.5 6H20" />
                <path d="m16.5 2.5 3.5 3.5-3.5 3.5" />
                <path d="M20 13v1.5a3.5 3.5 0 0 1-3.5 3.5H4" />
                <path d="m7.5 21.5-3.5-3.5 3.5-3.5" />
            </g>
            <!-- how to play: a question mark in a circle -->
            <g v-else-if="name === 'learn'">
                <circle cx="12" cy="12" r="9" />
                <path d="M9.5 9.5a2.5 2.5 0 1 1 3.4 2.3c-.6.3-.9.8-.9 1.4v.6" />
                <circle cx="12" cy="17" r="1.1" fill="currentColor" stroke="none" />
            </g>
            <!-- past days: a calendar -->
            <g v-else-if="name === 'history'">
                <rect x="3.5" y="5" width="17" height="15.5" rx="2" />
                <path d="M3.5 10h17M8 3v4M16 3v4" />
                <path d="m9 15 2 2 4-4" />
            </g>
            <!-- your games: a list -->
            <g v-else-if="name === 'games'">
                <path d="M9 6.5h11M9 12h11M9 17.5h11" />
                <circle cx="4.5" cy="6.5" r="1.2" fill="currentColor" stroke="none" />
                <circle cx="4.5" cy="12" r="1.2" fill="currentColor" stroke="none" />
                <circle cx="4.5" cy="17.5" r="1.2" fill="currentColor" stroke="none" />
            </g>
            <!-- this week: a calendar week -->
            <g v-else-if="name === 'week'">
                <rect x="3.5" y="5" width="17" height="15.5" rx="2" />
                <path d="M3.5 10h17M8 3v4M16 3v4" />
            </g>
            <!-- level: steps going up -->
            <path v-else-if="name === 'level'" d="M3.5 20h5v-5h5v-5h5V4.5" />
        </svg>
    </span>
</template>
