<script setup>
/* A small, non-interactive board: a preview with the spark and the numbers,
   or (with `times`) a solved burn map where every square has the colour of
   the minute it burned. Decorative: hidden from screen readers, the card
   around it says what it is. */
import { computed } from 'vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';
import { heatCell } from '@/lib/heat';
import { isDark } from '@/lib/theme';

const props = defineProps({
    rows: { type: Number, required: true },
    cols: { type: Number, required: true },
    spark: { type: Number, default: -1 },
    clues: { type: Array, default: () => [] }, // [[index, minute], ...]
    times: { type: Array, default: null }, // solved: minute per cell, -1 = firebreak
    showNumbers: { type: Boolean, default: true },
});

const cells = computed(() => {
    const n = props.rows * props.cols;
    const clueMap = new Map(props.clues);
    if (props.times) {
        const last = Math.max(1, ...props.times);
        return props.times.map((t, i) =>
            t < 0 ? { kind: 'break' } : { kind: i === props.spark ? 'spark' : 'burnt', ...heatCell(t, last, isDark.value) },
        );
    }
    const last = Math.max(1, ...clueMap.values());
    return Array.from({ length: n }, (_, i) => {
        if (i === props.spark) return { kind: 'spark', ...heatCell(0, last, isDark.value) };
        if (clueMap.has(i)) return { kind: 'clue', label: clueMap.get(i), ...heatCell(clueMap.get(i), last, isDark.value) };
        return { kind: 'empty' };
    });
});
</script>

<template>
    <div class="bf-mini-board" :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }" aria-hidden="true">
        <span
            v-for="(c, i) in cells"
            :key="i"
            :class="{ 'is-empty': c.kind === 'empty', 'is-break': c.kind === 'break' }"
            :style="c.bg ? { background: c.bg, color: c.fg } : null"
        >
            <FlameGlyph v-if="c.kind === 'spark' && showNumbers" />
            <template v-else-if="c.kind === 'clue' && showNumbers">{{ c.label }}</template>
        </span>
    </div>
</template>
