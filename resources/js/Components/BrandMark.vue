<script setup>
/* The Burnfront mark, "Steps": a rounded square in three steps of the heat
   ramp, the spark in the bottom-left corner and the front one step further
   each minute. `mono` draws the one-colour version (ink, smoke, ash). */
import { useId } from 'vue';

defineProps({
    mono: { type: Boolean, default: false },
    // legacy prop from the old flame mark; ignored
    knockout: { type: Boolean, default: false },
});

const clipId = `bf-mark-${useId()}`;
</script>

<template>
    <svg viewBox="0 0 60 60" class="bf-mark" aria-hidden="true">
        <clipPath :id="clipId"><rect width="60" height="60" rx="9" /></clipPath>
        <g :clip-path="`url(#${clipId})`">
            <rect width="60" height="60" :fill="mono ? '#16181B' : '#CC2162'" />
            <rect x="0" y="20" width="40" height="40" :fill="mono ? '#55585E' : '#FF7A1F'" />
            <rect x="0" y="40" width="20" height="20" :fill="mono ? '#F1F1EE' : '#FFE24A'" />
        </g>
    </svg>
</template>
