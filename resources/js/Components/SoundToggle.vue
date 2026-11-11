<script setup>
/* Sound on/off switch for the top bar, next to the light/dark switch. The
   speaker shows the state; the label says it in words for screen readers
   and as a tooltip. */
import { computed } from 'vue';
import { soundOn, toggleSound } from '@/lib/sound';
import { t } from '@/lib/i18n';

const label = computed(() => t(soundOn.value ? 'sound.on' : 'sound.off'));
</script>

<template>
    <button type="button" class="bf-icon-btn" :aria-label="label" :title="label" :aria-pressed="soundOn" @click="toggleSound">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" fill="currentColor" stroke-width="1.6" />
            <template v-if="soundOn">
                <path d="M15.5 9a4.2 4.2 0 0 1 0 6" />
                <path d="M18.2 6.4a8 8 0 0 1 0 11.2" />
            </template>
            <path v-else d="M16 9.5l5 5M21 9.5l-5 5" />
        </svg>
    </button>
</template>
