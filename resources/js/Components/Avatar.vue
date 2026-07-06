<script setup>
/* Circular badge shown top-right of the site bar: initials when signed in,
   a generic person glyph when signed out. Sized as a real touch target
   (44px) on mobile, tightened up once there's a mouse to aim with. */
defineProps({
    name: { type: String, default: '' },
});
</script>

<template>
    <span
        class="flex size-11 shrink-0 items-center justify-center rounded-full border border-rule-2 bg-folder font-mono text-sm font-bold tracking-[.02em] text-ember-hi transition-colors group-hover:border-ember group-focus-visible:border-ember sm:size-9 sm:text-[13px]"
    >
        <template v-if="name">{{ name.trim().charAt(0).toUpperCase() }}</template>
        <svg v-else viewBox="0 0 24 24" fill="none" class="size-5 text-ash transition-colors group-hover:text-ember group-focus-visible:text-ember" aria-hidden="true">
            <circle cx="12" cy="8" r="3.6" stroke="currentColor" stroke-width="1.7" />
            <path d="M4.5 19.2c1.4-3.6 4.4-5.4 7.5-5.4s6.1 1.8 7.5 5.4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
        </svg>
    </span>
</template>
