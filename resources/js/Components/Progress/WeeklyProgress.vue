<script setup>
/* Weekly goal (N squares) and rank bar. Every solved fire feeds both,
   whatever the mode: the rank and grade follow from the player's points
   (see lib/xp.js and lib/rank.js). Uses the Progressiekaart tokens: the
   rank bar is the shared .bf-progress bar, filled squares take the start
   of the heat ramp.

   only="week" shows just the weekly goal, for the result screen, where the
   points card right above already shows the rank. */
import { computed, onMounted, ref } from 'vue';
import { t } from '@/lib/i18n';
import { resolveProgress } from '@/lib/progress';
import { nextRankTitle, rankTitle } from '@/lib/rank';
import HomeIcon from '@/Components/HomeIcon.vue';

const props = defineProps({
    progress: { type: Object, default: null }, // server progress, null for a guest
    rules: { type: Object, default: null },
    only: { type: String, default: '' }, // '' (both) or 'week'
    celebrate: { type: Boolean, default: false }, // the newest square pops in (a fire was just solved)
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
    <section v-if="p" class="bf-card bf-weekly grid gap-4" :class="only === 'week' ? 'grid-cols-1' : 'grid-cols-2'">
        <div class="bf-weekly-goal flex flex-col gap-1.5" :aria-label="t('progress.weekAria', { done, goal })">
            <!-- the two parts wrap as whole pieces on a narrow phone, never word by word -->
            <span class="flex flex-wrap items-center gap-x-1.5 text-sm">
                <span class="flex items-center gap-1.5 whitespace-nowrap">
                    <HomeIcon name="week" :tile="false" />
                    <b class="font-semibold">{{ t('progress.weekTitle') }}</b>
                </span>
                <span class="whitespace-nowrap">{{ done >= goal ? t('progress.weekDone') : t('progress.weekCount', { done, goal }) }}</span>
            </span>
            <span class="flex gap-1.5" aria-hidden="true">
                <span
                    v-for="(filled, i) in squares"
                    :key="i"
                    class="bf-weekly-square h-[22px] w-[22px] rounded-[3px]"
                    :class="{ 'is-filled': filled, 'is-new': celebrate && filled && i === done - 1 }"
                    :style="filled ? { background: '#ff9a1f' } : { boxShadow: 'inset 0 0 0 1.5px var(--bf-line-strong)' }"
                ></span>
            </span>
        </div>
        <div v-if="only !== 'week'" class="bf-weekly-level flex flex-col gap-1.5" :aria-label="t('progress.levelAria', { rank: rankTitle(p.level, rules?.xp), toGo: p.toGo, next: nextRankTitle(p.level, rules?.xp) })">
            <span class="flex flex-wrap items-center gap-x-1.5 text-sm">
                <span class="flex items-center gap-1.5 whitespace-nowrap">
                    <HomeIcon name="level" :tile="false" />
                    <b class="font-semibold">{{ rankTitle(p.level, rules?.xp) }}</b>
                </span>
                <span class="whitespace-nowrap">{{ t('progress.levelToGo', { toGo: p.toGo }) }}</span>
            </span>
            <span class="bf-progress mt-auto" aria-hidden="true"><span :style="{ width: levelPct + '%' }"></span></span>
        </div>
    </section>
</template>
