<script setup>
import { Head, Link } from '@inertiajs/vue3';
import SiteBar from '@/Components/SiteBar.vue';

const props = defineProps({
    progress: { type: Object, required: true }, // {level, chapterKey, chapterLabel, xpIntoLevel, xpToNextLevel, totalXp, maxed}
    chapters: { type: Array, default: () => [] }, // [{key, label, levels: [{level, label, state: 'locked'|'current'|'reached'}]}]
    totalLevels: { type: Number, default: 20 },
});

const xpPct = props.progress.maxed ? 100 : Math.min(100, Math.round((props.progress.xpIntoLevel / props.progress.xpToNextLevel) * 100));

/* Flattens a chapter's levels into an alternating connector/node list so the
   template can drive a single TransitionGroup with one real element per
   iteration (each carrying its own stagger delay) instead of nesting v-for
   inside v-for, which TransitionGroup can't track cleanly. */
function pathItems(chapter) {
    const items = [];
    chapter.levels.forEach((node, idx) => {
        const delay = idx * 60 + 'ms';
        if (idx > 0) {
            items.push({ type: 'connector', key: `c${node.level}`, lit: node.state !== 'locked', delay });
        }
        items.push({ type: 'node', key: `n${node.level}`, delay, ...node });
    });
    return items;
}
</script>

<template>
    <Head title="Campaign · Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-8 px-4 pt-6 pb-16 sm:pt-10">
        <SiteBar :back="{ href: '/', text: 'Menu' }" />

        <header class="flex flex-col gap-2.5">
            <h1 class="font-staatliches text-[clamp(40px,9vw,56px)] leading-[0.95] font-normal tracking-[.035em] text-paper">
                CASE FILE
            </h1>
            <p class="max-w-[52ch] text-ash">
                Level {{ progress.level }} of {{ totalLevels }} &middot; {{ progress.chapterLabel }}
            </p>

            <div v-if="!progress.maxed" class="flex flex-col gap-1">
                <div class="bf-xp-track">
                    <div class="bf-xp-fill" :style="{ width: xpPct + '%' }"></div>
                </div>
                <span class="text-[11px] tracking-[.08em] text-ash-dim uppercase tabular-nums">
                    {{ progress.xpIntoLevel }} / {{ progress.xpToNextLevel }} XP to the next case
                </span>
            </div>
            <p v-else class="text-[12.5px] text-ember">Every case file in the record is closed.</p>
        </header>

        <section v-for="chapter in chapters" :key="chapter.key" class="bf-chapter" :aria-label="chapter.label">
            <h2 class="bf-chapter-title">Chapter {{ chapter.key }} &middot; {{ chapter.label }}</h2>
            <TransitionGroup tag="div" name="bf-node-in" appear class="bf-node-path">
                <template v-for="item in pathItems(chapter)" :key="item.key">
                    <div
                        v-if="item.type === 'connector'"
                        class="bf-node-connector"
                        :class="{ 'is-lit': item.lit }"
                        :style="{ '--delay': item.delay }"
                    ></div>
                    <Link
                        v-else-if="item.state === 'current'"
                        href="/campaign/play"
                        class="bf-node is-current"
                        :style="{ '--delay': item.delay }"
                        :aria-label="`${item.label}, current case`"
                    >
                        {{ item.level }}
                    </Link>
                    <div
                        v-else
                        class="bf-node"
                        :class="item.state === 'reached' ? 'is-reached' : 'is-locked'"
                        :style="{ '--delay': item.delay }"
                        :aria-label="`${item.label}, ${item.state === 'reached' ? 'closed' : 'locked'}`"
                    >
                        {{ item.level }}
                    </div>
                </template>
            </TransitionGroup>
        </section>
    </main>
</template>
