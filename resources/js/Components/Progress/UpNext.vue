<script setup>
/* "Up next": the home's main card once today's fire is solved. A campaign
   fire for a signed-in player, a quick fire for a guest. Data from
   NextFire::for(). */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { upNextText } from '@/lib/next-fire';

const props = defineProps({
    next: { type: Object, default: null },
});

const text = computed(() => upNextText(props.next));
const pct = computed(() => {
    const n = props.next;
    if (!n || n.kind !== 'campaign' || !n.totalLevels) return 0;
    return Math.round((n.solvedLevels / n.totalLevels) * 100);
});
</script>

<template>
    <section v-if="next && text" class="bf-upnext flex flex-col gap-3">
        <div class="flex flex-col gap-1">
            <span class="bf-eyebrow">{{ text.eyebrow }}</span>
            <span class="text-sm">{{ text.meta }}</span>
            <template v-if="text.progress">
                <span class="block h-1.5 overflow-hidden rounded" style="background: var(--bf-progress-track, #e4e4df)" aria-hidden="true">
                    <span class="block h-full" :style="{ width: pct + '%', background: 'var(--bf-progress-fill, #ff9a1f)' }"></span>
                </span>
                <span class="text-xs opacity-75">{{ text.progress }}</span>
            </template>
        </div>
        <Link :href="next.href" class="bf-btn bf-btn-primary justify-center">{{ text.action }}</Link>
    </section>
</template>
