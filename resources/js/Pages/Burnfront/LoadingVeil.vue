<script setup>
import FlameGlyph from '@/Components/FlameGlyph.vue';

defineProps({
    visible: { type: Boolean, default: false },
});

const GRID = 5;
const CENTER = 2;
const cells = Array.from({ length: GRID * GRID }, (_, i) => ({
    i,
    dist: Math.abs(Math.floor(i / GRID) - CENTER) + Math.abs((i % GRID) - CENTER),
}));
</script>

<template>
    <div class="bf-veil" :class="{ 'is-visible': visible }" role="status" aria-live="polite" aria-label="Verifying incident">
        <div class="bf-veil-body">
            <div class="bf-veil-grid" aria-hidden="true">
                <div
                    v-for="cell in cells"
                    :key="cell.i"
                    class="bf-veil-cell"
                    :class="{ 'is-spark': cell.dist === 0 }"
                    :style="{ '--delay': cell.dist * 90 + 'ms' }"
                >
                    <FlameGlyph v-if="cell.dist === 0" />
                </div>
            </div>
            <p class="bf-veil-log" aria-hidden="true">Verifying incident</p>
            <span class="bf-veil-progress" aria-hidden="true"><i></i></span>
        </div>
    </div>
</template>
