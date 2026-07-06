<script setup>
/* The Burnfront flame mark: one path (ember body) plus a scaled, brighter
   inner path (hot core). Used wherever the design calls for the fire glyph
   in place of a generic icon — the board's spark cell, the wordmark, and
   inline in body copy ("Fire spreads from <FlameGlyph>…"). */
defineProps({
    glow: { type: Boolean, default: false },
    knockout: { type: Boolean, default: false }, // single-tone, for placement on an ember-filled tile
});

const PATH =
    'M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z';
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        class="bf-cell-flame inline-block align-[-0.14em]"
        :style="glow ? { filter: 'drop-shadow(0 0 6px rgba(255,122,45,.5))' } : null"
        aria-hidden="true"
    >
        <path :d="PATH" :fill="knockout ? '#160d07' : '#ff7a2d'" />
        <path v-if="!knockout" :d="PATH" fill="#ffd36b" transform="translate(5.4 8.2) scale(.55)" />
    </svg>
</template>
