<script setup>
/* Round badge for the account link: the first letter of the player's name. */
defineProps({
    name: { type: String, default: '' },
});
</script>

<template>
    <span
        class="flex size-9 shrink-0 items-center justify-center rounded-full bg-ink font-sans text-[15px] font-semibold text-ground"
        aria-hidden="true"
    >
        {{ (name || '?').trim().charAt(0).toUpperCase() }}
    </span>
</template>
