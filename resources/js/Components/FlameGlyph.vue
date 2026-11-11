<script setup>
/* The spark: a four-point star that marks the cell where the fire started
   (minute 0). Kept under its old name so every board, the lesson and the
   loading veil pick up the new mark without touching their imports. It is
   drawn in currentColor-independent ink so it reads on the yellow spark
   cell in both light and dark mode. Props are kept for compatibility. */
defineProps({
    glow: { type: Boolean, default: false },
    knockout: { type: Boolean, default: false },
});

const STAR = 'M12 1.5 14.6 9.4 22.5 12 14.6 14.6 12 22.5 9.4 14.6 1.5 12 9.4 9.4Z';
</script>

<template>
    <svg viewBox="0 0 24 24" class="bf-cell-flame" aria-hidden="true"><path :d="STAR" /></svg>
</template>
