<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import RowArrow from '@/Components/RowArrow.vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';
import { plural } from '@/lib/plural';

const props = defineProps({
    entries: { type: Array, default: () => [] }, // {date, name, blurb, time_ms, hints_used, replayable}, most recent first
    streak: { type: Object, default: () => ({ current: 0, best: 0 }) },
});

// Last 14 calendar days (local time), oldest first, ending today.
const last14Days = computed(() => {
    const known = new Set(props.entries.map((e) => e.date));
    const today = new Date();
    const days = [];
    for (let i = 13; i >= 0; i--) {
        const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() - i);
        const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        days.push({ date: iso, filled: known.has(iso), label: niceDate(iso) });
    }
    return days;
});

function niceDate(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'long' });
}

const days = (n) => (n === 1 ? t('history.daily.daysOne') : t('history.daily.daysMany'));
</script>

<template>
    <Head :title="t('history.daily.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/account', text: t('history.back') }" />

        <main class="flex flex-col gap-4">
            <header class="bf-page-head pb-1">
                <p class="bf-eyebrow">{{ t('history.daily.eyebrow') }}</p>
                <h1 class="bf-page-title">{{ t('history.daily.title') }}</h1>
            </header>

            <div class="grid grid-cols-2 gap-2.5">
                <div class="bf-card flex flex-col gap-1">
                    <span class="bf-row-sub">{{ t('history.daily.streakNow') }}</span>
                    <span class="flex items-baseline gap-1.5">
                        <span class="bf-camp-num text-[34px]">{{ streak.current }}</span>
                        <span class="bf-row-sub">{{ days(streak.current) }}</span>
                    </span>
                </div>
                <div class="bf-card flex flex-col gap-1">
                    <span class="bf-row-sub">{{ t('history.daily.streakBest') }}</span>
                    <span class="flex items-baseline gap-1.5">
                        <span class="bf-camp-num text-[34px]">{{ streak.best }}</span>
                        <span class="bf-row-sub">{{ days(streak.best) }}</span>
                    </span>
                </div>
            </div>

            <section class="bf-card flex flex-col gap-2" :aria-label="t('history.daily.last14')">
                <span class="bf-row-sub">{{ t('history.daily.last14') }}</span>
                <ol class="bf-day-strip">
                    <li
                        v-for="day in last14Days"
                        :key="day.date"
                        :class="{ 'is-filled': day.filled }"
                        :title="day.label"
                        :aria-label="t(day.filled ? 'history.daily.dayPlayed' : 'history.daily.dayMissed', { date: day.label })"
                    ></li>
                </ol>
            </section>

            <section v-if="entries.length" class="bf-list" :aria-label="t('history.daily.eyebrow')">
                <template v-for="entry in entries" :key="entry.date">
                    <Link
                        v-if="entry.replayable"
                        :href="`/daily/history/play?date=${entry.date}`"
                        class="bf-row"
                        :aria-label="t('history.daily.playAgain', { date: niceDate(entry.date) })"
                    >
                        <span class="bf-row-text">
                            <span class="bf-row-title">{{ niceDate(entry.date) }}</span>
                            <span class="bf-row-sub truncate">{{ entry.name || t('history.daily.unnamed') }}</span>
                            <span class="bf-row-sub">{{ plural('history.hints', entry.hints_used ?? 0) }}</span>
                        </span>
                        <span class="bf-history-time">{{ fmtClock(entry.time_ms) }}</span>
                        <RowArrow />
                    </Link>
                    <div v-else class="bf-row">
                        <span class="bf-row-text">
                            <span class="bf-row-title">{{ niceDate(entry.date) }}</span>
                            <span class="bf-row-sub">{{ t('history.daily.noReplay') }}</span>
                            <span class="bf-row-sub">{{ plural('history.hints', entry.hints_used ?? 0) }}</span>
                        </span>
                        <span class="bf-history-time">{{ fmtClock(entry.time_ms) }}</span>
                    </div>
                </template>
            </section>
            <p v-else class="bf-lede">{{ t('history.daily.empty') }}</p>
        </main>
    </div>
</template>
