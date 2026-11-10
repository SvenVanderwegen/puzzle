<script setup>
/* One-tap quick fire buttons (5 by 5, 6 by 6, 8 by 8): a fresh fire with
   no setup screen and no clock. Data from NextFire::quickFires(). */
import { Link } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';

defineProps({
    fires: { type: Array, default: () => [] }, // [{difficulty, rows, cols, breaks, href}]
});
</script>

<template>
    <section v-if="fires.length" class="bf-quick flex flex-col gap-2.5">
        <div class="flex flex-col">
            <span class="font-semibold">{{ t('progress.quickTitle') }}</span>
            <span class="text-sm opacity-75">{{ t('progress.quickBody') }}</span>
        </div>
        <div class="grid gap-2" :style="{ gridTemplateColumns: `repeat(${fires.length}, minmax(0, 1fr))` }">
            <Link v-for="fire in fires" :key="fire.difficulty" :href="fire.href" class="bf-btn bf-quick-size justify-center">
                {{ t('progress.quickSize', { rows: fire.rows, cols: fire.cols }) }}
            </Link>
        </div>
    </section>
</template>
