<script setup>
/* Weekly goal (N squares) and level bar. Every solved fire feeds both,
   whatever the mode. Styling is deliberately minimal (bf-progress-* hooks)
   so the visual redesign can restyle it without touching the logic. */
import { computed, onMounted, ref } from 'vue';
import { t } from '@/lib/i18n';
import { resolveProgress } from '@/lib/progress';

const props = defineProps({
    progress: { type: Object, default: null }, // server progress, null for a guest
    rules: { type: Object, default: null },
});

/* A guest's numbers come from localStorage, which only exists in the
   browser: read it after mount so server and client render the same. */
const local = ref(null);
onMounted(() => {
    if (!props.progress) local.value = resolveProgress(null, props.rules);
});

const p = computed(() => props.progress ?? local.value);
const goal = computed(() => p.value?.weekGoal ?? props.rules?.weekGoal ?? 5);
const done = computed(() => Math.min(p.value?.weekSolved ?? 0, goal.value));
const squares = computed(() => Array.from({ length: goal.value }, (_, i) => i < done.value));
const levelPct = computed(() => {
    const v = p.value;
    if (!v || !v.levelSize) return 0;
    return Math.round((v.intoLevel / v.levelSize) * 100);
});
</script>

<template>
    <section v-if="p" class="bf-progress grid grid-cols-2 gap-4">
        <div class="bf-progress-week flex flex-col gap-1.5" :aria-label="t('progress.weekAria', { done, goal })">
            <span class="text-sm">
                <b class="font-semibold">{{ t('progress.weekTitle') }}</b>
                &middot;
                {{ done >= goal ? t('progress.weekDone') : t('progress.weekCount', { done, goal }) }}
            </span>
            <span class="flex gap-1.5" aria-hidden="true">
                <span
                    v-for="(filled, i) in squares"
                    :key="i"
                    class="bf-progress-square h-[22px] w-[22px] rounded-[3px]"
                    :class="{ 'is-filled': filled }"
                    :style="filled ? { background: 'var(--bf-progress-fill, #ff9a1f)' } : { boxShadow: 'inset 0 0 0 1.5px var(--bf-progress-empty, #b9b9b2)' }"
                ></span>
            </span>
        </div>
        <div class="bf-progress-level flex flex-col gap-1.5" :aria-label="t('progress.levelAria', { level: p.level, toGo: p.toGo })">
            <span class="text-sm">
                <b class="font-semibold">{{ t('progress.levelTitle', { level: p.level }) }}</b>
                &middot;
                {{ t('progress.levelToGo', { toGo: p.toGo }) }}
            </span>
            <span class="bf-progress-bar block h-[22px] overflow-hidden rounded-[3px]" style="background: var(--bf-progress-track, #e4e4df)" aria-hidden="true">
                <span class="block h-full" :style="{ width: levelPct + '%', background: 'var(--bf-progress-fill, #ff9a1f)' }"></span>
            </span>
        </div>
    </section>
</template>
