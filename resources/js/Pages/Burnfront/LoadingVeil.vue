<script setup>
/* Shown while a new fire is being made: a small board burning outward from
   the spark in the colours of the heat ramp. */
import FlameGlyph from '@/Components/FlameGlyph.vue';
import { heatColor } from '@/lib/heat';
import { t } from '@/lib/i18n';

defineProps({
    visible: { type: Boolean, default: false },
});

const GRID = 5;
const CENTER = 2;
const cells = Array.from({ length: GRID * GRID }, (_, i) => {
    const dist = Math.abs(Math.floor(i / GRID) - CENTER) + Math.abs((i % GRID) - CENTER);
    return { i, dist, color: heatColor(dist / 4) };
});
</script>

<template>
    <div class="bf-veil" :class="{ 'is-visible': visible }" role="status" aria-live="polite" :aria-label="t('common.loading')">
        <div class="bf-veil-body">
            <div class="bf-veil-grid" aria-hidden="true">
                <div
                    v-for="cell in cells"
                    :key="cell.i"
                    class="bf-veil-cell"
                    :class="{ 'is-spark': cell.dist === 0 }"
                    :style="{ '--delay': cell.dist * 90 + 'ms', '--heat-bg': cell.color }"
                >
                    <FlameGlyph v-if="cell.dist === 0" />
                </div>
            </div>
            <p class="bf-veil-log" aria-hidden="true">{{ t('common.loading') }}</p>
            <span class="bf-veil-progress" aria-hidden="true"><i></i></span>
        </div>
    </div>
</template>
