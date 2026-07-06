<script setup>
/* A dossier rubber stamp: rotated, bordered, uppercase Staatliches label.
   tone drives the color (ember = CONTAINED, steel = VERIFIED, void = VOID/
   DISCREPANCY, dashed = a locked/inert stamp). */
defineProps({
    tone: { type: String, default: 'ember' }, // 'ember' | 'steel' | 'void' | 'dashed'
    size: { type: String, default: 'md' }, // 'sm' | 'md' | 'lg'
    rotate: { type: Number, default: -4 },
});

const SIZES = {
    sm: 'text-[11px] px-1.5 py-0',
    md: 'text-[13px] px-2.5 py-0.5',
    lg: 'text-[38px] px-4.5 py-1.5 border-[3px]!',
};
</script>

<template>
    <span
        class="bf-stamp"
        :class="[`bf-stamp-${tone}`, SIZES[size] ?? SIZES.md]"
        :style="{ transform: `rotate(${rotate}deg)` }"
    >
        <slot />
    </span>
</template>
