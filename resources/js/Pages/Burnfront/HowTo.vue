<script setup>
/* How to play (/how-to): the rules on one calm page, for a player who wants
   to read them back. Learning by doing is the lesson's job (/learn), so this
   page points there first and keeps the same words. The picture is the
   lesson's own board, solved. */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';
import { heatCell } from '@/lib/heat';
import { isDark } from '@/lib/theme';
import { LESSON, burn, emptyMarks } from '@/lib/burnfront-lesson';

const FIRST_FIRE_HREF = '/endless/play?difficulty=lookout';

const picture = computed(() => {
    const marks = emptyMarks();
    marks[9] = 1;
    marks[13] = 1;
    return burn(marks).map((d, i) => {
        if (d < 0) return { i, kind: 'break', label: '' };
        const colours = heatCell(d, LESSON.maxMinute, isDark.value);
        if (d === 0) return { i, kind: 'spark', label: '', ...colours };
        return { i, kind: LESSON.clues[i] != null ? 'clue' : 'burnt', label: String(d), ...colours };
    });
});

const rules = ['spark', 'spread', 'number', 'breaks', 'burn'];
const taps = ['once', 'twice', 'thrice', 'check', 'help'];
const tips = ['count', 'neighbours', 'closedIn', 'colours', 'noHurry'];
</script>

<template>
    <Head :title="t('howto.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/', text: t('common.home') }" />

        <main class="bf-howto">
            <header class="bf-page-head pb-1">
                <p class="bf-eyebrow">{{ t('howto.eyebrow') }}</p>
                <h1 class="bf-page-title">{{ t('howto.title') }}</h1>
                <p class="bf-lede">{{ t('howto.lead') }}</p>
            </header>

            <section class="bf-card bf-howto-lesson" aria-labelledby="bf-howto-lesson">
                <div class="bf-howto-picture" role="img" :aria-label="t('howto.pictureLabel')">
                    <span
                        v-for="cell in picture"
                        :key="cell.i"
                        :class="`is-${cell.kind}`"
                        :style="cell.bg ? { background: cell.bg, color: cell.fg } : null"
                    >
                        <svg v-if="cell.kind === 'spark'" aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M12 1.5 14.6 9.4 22.5 12 14.6 14.6 12 22.5 9.4 14.6 1.5 12 9.4 9.4Z" />
                        </svg>
                        <template v-else>{{ cell.label }}</template>
                    </span>
                </div>
                <div class="bf-howto-lesson-copy">
                    <h2 id="bf-howto-lesson">{{ t('howto.lesson.title') }}</h2>
                    <p>{{ t('howto.lesson.body') }}</p>
                    <Link href="/learn" class="bf-btn bf-btn-primary">{{ t('howto.lesson.start') }}</Link>
                </div>
            </section>

            <section class="bf-howto-block" aria-labelledby="bf-howto-rules">
                <h2 id="bf-howto-rules">{{ t('howto.rules.title') }}</h2>
                <ol class="bf-howto-steps">
                    <li v-for="key in rules" :key="key">{{ t(`howto.rules.${key}`) }}</li>
                </ol>
            </section>

            <section class="bf-howto-block" aria-labelledby="bf-howto-tap">
                <h2 id="bf-howto-tap">{{ t('howto.tap.title') }}</h2>
                <ul class="bf-howto-list">
                    <li v-for="key in taps" :key="key">{{ t(`howto.tap.${key}`) }}</li>
                </ul>
            </section>

            <section class="bf-howto-block" aria-labelledby="bf-howto-tips">
                <h2 id="bf-howto-tips">{{ t('howto.tips.title') }}</h2>
                <ul class="bf-howto-list">
                    <li v-for="key in tips" :key="key">{{ t(`howto.tips.${key}`) }}</li>
                </ul>
            </section>

            <footer class="bf-howto-actions">
                <Link :href="FIRST_FIRE_HREF" class="bf-btn bf-btn-primary">{{ t('howto.play') }}</Link>
                <Link href="/endless" class="bf-btn">{{ t('howto.practice') }}</Link>
            </footer>
        </main>
    </div>
</template>
