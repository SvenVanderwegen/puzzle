<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { fmtClock } from '@/lib/burnfront-engine';
import SiteBar from '@/Components/SiteBar.vue';
import RubberStamp from '@/Components/RubberStamp.vue';

const props = defineProps({
    entries: { type: Array, default: () => [] }, // {date, name, blurb, time_ms, hints_used, replayable}, most recent first
    streak: { type: Object, default: () => ({ current: 0, best: 0 }) },
});

// Last 14 calendar days (local time), oldest first, ending today — used to
// paint the streak strip against the incidents actually on record.
const last14Days = computed(() => {
    const known = new Set(props.entries.map((e) => e.date));
    const today = new Date();
    const days = [];
    for (let i = 13; i >= 0; i--) {
        const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() - i);
        const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        days.push({ date: iso, filled: known.has(iso) });
    }
    return days;
});
</script>

<template>
    <Head title="Daily History — Burnfront" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-3 pb-16 sm:pt-5">
        <SiteBar :back="{ href: '/account', text: 'Account' }" />

        <header class="flex flex-col gap-2">
            <p class="bf-eyebrow">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-stock">Daily History</h1>
        </header>

        <div class="flex gap-3">
            <div class="flex flex-1 flex-col gap-1.5 rounded-lg border border-rule-2 bg-folder p-3.5">
                <span class="font-mono text-[11px] tracking-[.14em] text-ash-dim uppercase">Current streak</span>
                <span class="flex items-baseline gap-1.5">
                    <span class="font-staatliches text-3xl text-ember">{{ streak.current }}</span>
                    <span class="font-mono text-[11px] text-ash-dim uppercase">days</span>
                </span>
            </div>
            <div class="flex flex-1 flex-col gap-1.5 rounded-lg border border-rule-2 bg-folder p-3.5">
                <span class="font-mono text-[11px] tracking-[.14em] text-ash-dim uppercase">Best streak</span>
                <span class="flex items-baseline gap-1.5">
                    <span class="font-staatliches text-3xl text-stock">{{ streak.best }}</span>
                    <span class="font-mono text-[11px] text-ash-dim uppercase">days</span>
                </span>
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="font-mono text-[11px] tracking-[.14em] text-ash-dim uppercase">Last 14 days</span>
            <div class="flex gap-1">
                <div
                    v-for="day in last14Days"
                    :key="day.date"
                    class="day-cell h-5 flex-1 rounded-sm"
                    :class="day.filled ? 'is-filled' : 'border border-rule bg-raised'"
                    :title="day.date"
                ></div>
            </div>
        </div>

        <section v-if="entries.length" aria-label="Past daily incidents" class="rounded-lg border border-rule-2 overflow-hidden">
            <template v-for="(entry, i) in entries" :key="entry.date">
                <Link
                    v-if="entry.replayable"
                    :href="`/daily/history/play?date=${entry.date}`"
                    class="flex items-center gap-3 px-4 py-3.5 transition-colors duration-150 hover:bg-ember/[.07] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame"
                    :class="[i % 2 === 0 ? 'bg-folder' : 'bg-[#160f0c]', i < entries.length - 1 ? 'border-b border-rule' : '']"
                >
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-mono text-ash">{{ entry.date }}</span>
                        <span class="truncate text-sm text-stock">
                            {{ entry.name || 'Unnamed incident' }}<template v-if="entry.blurb"> — {{ entry.blurb }}</template>
                        </span>
                        <span class="font-mono text-[12px] font-semibold tracking-[.08em] text-ash-dim uppercase">
                            <template v-if="entry.hints_used === 0">clean</template>
                            <template v-else>{{ entry.hints_used }} hint{{ entry.hints_used === 1 ? '' : 's' }}</template>
                        </span>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1.5">
                        <span class="font-mono font-bold text-stock">{{ fmtClock(entry.time_ms) }}</span>
                        <RubberStamp tone="ember" size="sm">Contained</RubberStamp>
                    </div>
                </Link>
                <div
                    v-else
                    class="flex cursor-default items-center gap-3 px-4 py-3.5"
                    :class="[i % 2 === 0 ? 'bg-folder' : 'bg-[#160f0c]', i < entries.length - 1 ? 'border-b border-rule' : '']"
                >
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-mono text-ash">{{ entry.date }}</span>
                        <span class="text-sm text-ash-dim">Case filed before this record kept incident details — no replay available.</span>
                        <span class="font-mono text-[12px] font-semibold tracking-[.08em] text-ash-dim uppercase">
                            <template v-if="entry.hints_used === 0">clean</template>
                            <template v-else>{{ entry.hints_used }} hint{{ entry.hints_used === 1 ? '' : 's' }}</template>
                        </span>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1.5">
                        <span class="font-mono font-bold text-stock">{{ fmtClock(entry.time_ms) }}</span>
                        <RubberStamp tone="ember" size="sm">Contained</RubberStamp>
                    </div>
                </div>
            </template>
        </section>
        <p v-else class="text-sm text-ash">No closed cases yet — solve today&rsquo;s daily incident to start your record.</p>
    </main>
</template>

<style scoped>
.day-cell.is-filled {
    background: linear-gradient(180deg, var(--color-ember-hi), var(--color-ember-deep));
}
</style>
