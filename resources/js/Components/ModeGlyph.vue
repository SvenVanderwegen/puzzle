<script setup>
defineProps({
    mode: { type: String, required: true },
});
</script>

<template>
    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <g v-if="mode === 'daily'">
            <path d="M9 34h30M13 29h22" />
            <path d="M16 29a8 8 0 0 1 16 0" />
            <path d="M24 9v6M11.5 14.5l4.2 4.2M36.5 14.5l-4.2 4.2" />
            <circle cx="24" cy="29" r="2.5" fill="currentColor" stroke="none" />
        </g>

        <g v-else-if="mode === 'campaign'">
            <path d="M24 7 36 11v10c0 8.5-4.9 14.9-12 19-7.1-4.1-12-10.5-12-19V11L24 7Z" />
            <path d="m18 24 4 4 8-9" />
            <circle cx="12" cy="11" r="2" fill="currentColor" stroke="none" />
            <circle cx="36" cy="11" r="2" fill="currentColor" stroke="none" />
        </g>

        <g v-else-if="mode === 'endless'">
            <path d="M24 24c-4.5-7-7.6-10-12-10a10 10 0 0 0 0 20c4.4 0 7.5-3 12-10Z" />
            <path d="M24 24c4.5-7 7.6-10 12-10a10 10 0 0 1 0 20c-4.4 0-7.5-3-12-10Z" />
            <path d="m37 9 3 5-5 2" />
        </g>

        <g v-else>
            <path d="M11 10h18a8 8 0 0 1 8 8v20H19a8 8 0 0 1-8-8V10Z" />
            <path d="M19 10v28M25 20h6M25 26h6" />
            <circle cx="15" cy="16" r="1.5" fill="currentColor" stroke="none" />
        </g>
    </svg>
</template>
