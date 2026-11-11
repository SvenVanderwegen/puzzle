<script setup>
/* Light / dark switch for the top bar. One tap steps through: follow the
   device, light, dark. The icon shows what is chosen; the label says it in
   words for screen readers and as a tooltip. */
import { computed } from 'vue';
import { cycleTheme, themeChoice } from '@/lib/theme';
import { t } from '@/lib/i18n';

const label = computed(() => t(`common.theme.${themeChoice.value}`));
</script>

<template>
    <button type="button" class="bf-icon-btn" :aria-label="label" :title="label" @click="cycleTheme">
        <svg v-if="themeChoice === 'light'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4.2" />
            <path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6" />
        </svg>
        <svg v-else-if="themeChoice === 'dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" />
        </svg>
        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" />
            <path d="M12 3.5a8.5 8.5 0 0 1 0 17Z" fill="currentColor" stroke="none" />
        </svg>
    </button>
</template>
