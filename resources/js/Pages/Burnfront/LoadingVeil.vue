<script setup>
/* Full-screen loading veil shown while the incident desk generates or fetches
   a puzzle. The mini grid replays the game's own core image — a wavefront
   spreading one ring per minute from a spark — on a 5x5 board sized like the
   smallest ("Lookout") tier, so the loading state teaches the mechanic
   instead of just spinning. */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';

const props = defineProps({
    visible: { type: Boolean, default: false },
});

const PHRASES = ['Pulling sensor log…', 'Cross-referencing arrival times…', 'Tracing the containment line…', 'Verifying the solve is forced…'];

const GRID = 5;
const CENTER = 2;
const cells = Array.from({ length: GRID * GRID }, (_, i) => {
    const dist = Math.abs(Math.floor(i / GRID) - CENTER) + Math.abs((i % GRID) - CENTER);
    return { i, dist };
});

const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const phraseIndex = ref(0);
const phrase = computed(() => PHRASES[phraseIndex.value]);
let timer = null;

function stop() {
    clearInterval(timer);
    timer = null;
}

function start() {
    stop();
    if (reducedMotion) return;
    timer = setInterval(() => {
        phraseIndex.value = (phraseIndex.value + 1) % PHRASES.length;
    }, 2000);
}

watch(
    () => props.visible,
    (v) => {
        phraseIndex.value = 0;
        if (v) start();
        else stop();
    },
    { immediate: true },
);

onBeforeUnmount(stop);
</script>

<template>
    <div class="bf-veil" :class="{ 'is-visible': visible }" role="status" aria-live="polite">
        <div class="bf-veil-body">
            <div class="bf-veil-grid" aria-hidden="true">
                <div
                    v-for="cell in cells"
                    :key="cell.i"
                    class="bf-veil-cell"
                    :class="{ 'is-spark': cell.dist === 0 }"
                    :style="{ '--delay': cell.dist * 220 + 'ms' }"
                >
                    <FlameGlyph v-if="cell.dist === 0" />
                </div>
            </div>
            <p class="bf-veil-log">
                <span>{{ phrase }}</span><span class="bf-veil-cursor"></span>
            </p>
        </div>
    </div>
</template>
