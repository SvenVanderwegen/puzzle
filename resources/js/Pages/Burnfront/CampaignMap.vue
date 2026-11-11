<script setup>
/* The campaign: 20 fires in five parts, played in order. Finished fires are
   filled with the heat colour for how far along the campaign they are (colour
   = progress, the same yellow-to-violet scale as the board); the fire you are
   on is outlined in ink and is the only one you can open. */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import RowArrow from '@/Components/RowArrow.vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';
import { heatCell } from '@/lib/heat';
import { isDark } from '@/lib/theme';

const props = defineProps({
    progress: { type: Object, required: true }, // {level, chapterKey, chapterLabel, xpIntoLevel, xpToNextLevel, totalXp, maxed}
    chapters: { type: Array, default: () => [] }, // [{key, label, levels: [{level, label, state: 'locked'|'current'|'reached'}]}]
    totalLevels: { type: Number, default: 20 },
});

const allLevels = computed(() => props.chapters.flatMap((c) => c.levels));
const done = computed(() => allLevels.value.filter((l) => l.state === 'reached').length);
const current = computed(() => allLevels.value.find((l) => l.state === 'current') ?? null);
const pct = computed(() => Math.round((done.value / Math.max(1, props.totalLevels)) * 100));

function partStatus(chapter) {
    if (chapter.levels.some((l) => l.state === 'current')) return 'now';
    if (chapter.levels.every((l) => l.state === 'reached')) return 'done';
    return 'later';
}

function chapterTitle(chapter) {
    const key = `common.chapter.${chapter.key}`;
    const v = t(key);
    return v === key ? chapter.label : v;
}

function tileStyle(node) {
    if (node.state !== 'reached') return null;
    const c = heatCell(node.level - 1, Math.max(1, props.totalLevels - 1), isDark.value);
    return { background: c.bg, color: c.fg };
}

function tileLabel(node) {
    if (node.state === 'reached') return t('campaign.fireDone', { n: node.level });
    if (node.state === 'current') return t('campaign.fireNow', { n: node.level });
    return t('campaign.fireLocked', { n: node.level });
}
</script>

<template>
    <Head :title="t('campaign.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/', text: t('common.home') }" />

        <main class="flex flex-col gap-4">
            <header class="bf-page-head pb-1">
                <p class="bf-eyebrow">{{ t('campaign.eyebrow') }}</p>
                <h1 class="bf-page-title">{{ t('campaign.title') }}</h1>
                <p class="bf-lede">{{ t('campaign.lead') }}</p>
            </header>

            <section class="bf-card flex flex-col gap-3">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="bf-row-title">{{ t('campaign.progress', { done, total: totalLevels }) }}</span>
                    <span class="bf-mono-label tabular-nums">{{ pct }}%</span>
                </div>
                <span class="bf-progress" aria-hidden="true"><span :style="{ width: pct + '%' }"></span></span>
                <p class="bf-row-sub m-0">
                    {{
                        progress.maxed
                            ? t('campaign.levelMax', { level: progress.level })
                            : t('campaign.level', {
                                  level: progress.level,
                                  xp: progress.xpIntoLevel,
                                  next: progress.xpToNextLevel,
                                  nextLevel: progress.level + 1,
                              })
                    }}
                </p>
                <Link v-if="current" href="/campaign/play" class="bf-btn bf-btn-primary bf-btn-lg mt-1 w-full">
                    {{ t('campaign.playNext', { n: current.level }) }}
                </Link>
                <p v-else-if="done >= totalLevels" class="bf-row-sub m-0">{{ t('campaign.allDone') }}</p>
            </section>

            <section v-for="chapter in chapters" :key="chapter.key" class="bf-camp-part" :class="`is-${partStatus(chapter)}`">
                <header class="bf-camp-part-head">
                    <h2 class="bf-row-title m-0">{{ chapterTitle(chapter) }}</h2>
                    <span class="bf-camp-part-status">{{ t(`campaign.part${partStatus(chapter) === 'now' ? 'Now' : partStatus(chapter) === 'done' ? 'Done' : 'Later'}`) }}</span>
                </header>
                <ol class="bf-camp-tiles">
                    <li v-for="node in chapter.levels" :key="node.level">
                        <Link
                            v-if="node.state === 'current'"
                            href="/campaign/play"
                            class="bf-camp-tile is-current"
                            :aria-label="tileLabel(node)"
                        >
                            <span class="bf-camp-num">{{ node.level }}</span>
                            <span class="bf-camp-tag">{{ t('campaign.play') }}<RowArrow /></span>
                        </Link>
                        <span
                            v-else
                            class="bf-camp-tile"
                            :class="node.state === 'reached' ? 'is-done' : 'is-locked'"
                            :style="tileStyle(node)"
                            role="img"
                            :aria-label="tileLabel(node)"
                        >
                            <span class="bf-camp-num">{{ node.level }}</span>
                            <svg v-if="node.state === 'reached'" class="bf-camp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                            <svg v-else class="bf-camp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                        </span>
                    </li>
                </ol>
            </section>
        </main>
    </div>
</template>
