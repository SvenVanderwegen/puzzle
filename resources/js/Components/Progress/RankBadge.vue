<script setup>
/* A rank badge: a square tile, the same shape as a board cell, in the
   rank's own step of the heat ramp (yellow for the first rank, violet for
   the last, so colour = how far you have come). On it, the plainest
   possible insignia: one, two or three stripes, and a star for every three
   ranks. More marks = higher rank.

   Decoration only: always put the rank's name next to it in words. */
import { computed } from 'vue';
import { heatCell } from '@/lib/heat';
import { isDark } from '@/lib/theme';
import { DEFAULT_XP_RULES, rankStep } from '@/lib/xp';

const props = defineProps({
    rank: { type: Number, default: 0 }, // index, 0 = first rank
    size: { type: Number, default: 44 }, // px
    rules: { type: Object, default: null },
    muted: { type: Boolean, default: false }, // a rank not reached yet: outline only
});

const colours = computed(() => heatCell(rankStep(props.rank, props.rules ?? DEFAULT_XP_RULES), 1, isDark.value));
const stripes = computed(() => (props.rank % 3) + 1);
const stars = computed(() => Math.floor(props.rank / 3));

/* Stripes are stacked from the bottom; with stars they sit lower. */
const stripePaths = computed(() => {
    const n = stripes.value;
    const gap = 7;
    const height = 7;
    const blockHeight = height + (n - 1) * gap;
    const bottom = stars.value > 0 ? 39 : 24 + blockHeight / 2;
    return Array.from({ length: n }, (_, i) => {
        const y = bottom - i * gap;
        return `M13 ${y} L24 ${y - height} L35 ${y}`;
    });
});

const starCentres = computed(() => {
    if (stars.value === 1) return [24];
    if (stars.value >= 2) return [17, 31];
    return [];
});

function starPath(cx, cy = 12.5, outer = 5.6, inner = 2.4) {
    let d = '';
    for (let i = 0; i < 10; i++) {
        const r = i % 2 === 0 ? outer : inner;
        const a = -Math.PI / 2 + (i * Math.PI) / 5;
        d += `${i === 0 ? 'M' : 'L'}${(cx + r * Math.cos(a)).toFixed(2)} ${(cy + r * Math.sin(a)).toFixed(2)} `;
    }
    return d + 'Z';
}
</script>

<template>
    <span
        class="bf-rank-badge"
        :class="{ 'is-muted': muted }"
        :style="{ width: size + 'px', height: size + 'px', '--badge-bg': colours.bg, '--badge-fg': colours.fg }"
        aria-hidden="true"
    >
        <svg viewBox="0 0 48 48" fill="none">
            <path v-for="(d, i) in stripePaths" :key="'s' + i" :d="d" stroke="currentColor" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round" />
            <path v-for="cx in starCentres" :key="'x' + cx" :d="starPath(cx)" fill="currentColor" />
        </svg>
    </span>
</template>
