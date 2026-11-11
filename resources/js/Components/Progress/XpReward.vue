<script setup>
/* The points a solved fire gave, counted up step by step on the result
   screen: each line of the reward lights up in turn (fire, no hints,
   today's fire, days in a row, weekly goal), the total counts along, and
   the level bar fills. When the bar is full the level goes up with a small
   burst; when that level starts a new rank, a "New rank" card opens.

   Nothing here decides anything: the reward ({items, total, xpBefore,
   xpAfter}) comes from the server (PlayerProgress::award()) or, for a
   guest, from lib/progress.js. This only shows it.

   The card keeps one size whatever the reward (the lines have a reserved
   area), so nothing on the page moves while it plays. With "reduce motion"
   switched on in the device, the end state is shown at once. */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import RankBadge from '@/Components/Progress/RankBadge.vue';
import { heatColor } from '@/lib/heat';
import { t } from '@/lib/i18n';
import { play } from '@/lib/sound';
import { isDark } from '@/lib/theme';
import { DEFAULT_XP_RULES, rankStep, standing } from '@/lib/xp';

const props = defineProps({
    reward: { type: Object, default: null }, // {items: [{key, xp, n?}], total, xpBefore, xpAfter}; null while the server is still answering
    rules: { type: Object, default: null }, // the points rules (progressRules.xp)
});
const emit = defineEmits(['done']);

const xpRules = computed(() => props.rules ?? DEFAULT_XP_RULES);
const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const shownXp = ref(0); /* the points total the bar shows right now (a fraction while it fills) */
const counted = ref(0); /* the "+N" counter */
const revealed = ref(0); /* how many lines are lit */
const shownRank = ref(0); /* the badge keeps the old rank until the "New rank" card opens */
const levelNote = ref(null); /* the level just reached, shown for a moment in place of "N points to go" */
const bumpKey = ref(0);
const sparks = ref([]);
const finished = ref(false);
const rankUpOpen = ref(false);
const confetti = ref([]);
const continueButton = ref(null);

const now = computed(() => standing(shownXp.value, xpRules.value));
const barPct = computed(() => Math.min(100, (now.value.intoLevel / now.value.levelSize) * 100));
const after = computed(() => (props.reward ? standing(props.reward.xpAfter, xpRules.value) : null));
const rankName = (rank) => t(`progress.rank.${xpRules.value.ranks[rank]}`);
const allHinted = computed(() => props.reward?.items?.[0]?.xp === 0);

function lineLabel(item) {
    return t(`progress.reward.line.${item.key}`, { n: item.n ?? '' });
}

let runId = 0;
let frame = 0;
let timers = [];
let running = false;

function stop() {
    runId++;
    running = false;
    cancelAnimationFrame(frame);
    timers.forEach(clearTimeout);
    timers = [];
}

function wait(ms) {
    return new Promise((resolve) => timers.push(setTimeout(resolve, ms)));
}

const easeOut = (u) => 1 - Math.pow(1 - u, 3);

function tween(ms, step) {
    return new Promise((resolve) => {
        const started = performance.now();
        const tick = (at) => {
            const u = Math.min(1, (at - started) / ms);
            step(easeOut(u));
            if (u < 1) frame = requestAnimationFrame(tick);
            else resolve();
        };
        frame = requestAnimationFrame(tick);
    });
}

/* A handful of small squares (board cells, in the heat colours) flying off
   the end of the bar. */
function burst() {
    const made = Array.from({ length: 14 }, (_, i) => {
        const angle = Math.PI * (0.15 + 0.7 * Math.random()) + Math.PI; /* up and to both sides */
        const far = 34 + Math.random() * 46;
        return {
            id: `${bumpKey.value}-${i}`,
            style: {
                '--dx': `${Math.min(16, Math.round(Math.cos(angle) * far - 28))}px`, /* mostly back over the bar, never off the page */
                '--dy': `${Math.round(Math.sin(angle) * far)}px`,
                '--rot': `${Math.round(Math.random() * 360 - 180)}deg`,
                background: heatColor(Math.random() * 0.75, isDark.value),
                animationDelay: `${Math.round(Math.random() * 90)}ms`,
            },
        };
    });
    sparks.value = made;
    timers.push(setTimeout(() => (sparks.value = []), 1100));
}

function levelUp(level) {
    bumpKey.value++;
    levelNote.value = level;
    play('level');
    burst();
    const key = bumpKey.value;
    timers.push(
        setTimeout(() => {
            if (bumpKey.value === key) levelNote.value = null;
        }, 2200),
    );
}

watch(
    () => now.value.level,
    (level, was) => {
        if (running && level > was) levelUp(level);
    },
);

function openRankUp() {
    confetti.value = reducedMotion
        ? []
        : Array.from({ length: 44 }, (_, i) => {
              const size = 7 + Math.round(Math.random() * 7);
              return {
                  id: i,
                  style: {
                      left: `${Math.round(Math.random() * 100)}%`,
                      width: `${size}px`,
                      height: `${size}px`,
                      background: heatColor(Math.random(), isDark.value),
                      '--drift': `${Math.round(Math.random() * 120 - 60)}px`,
                      '--rot': `${Math.round(Math.random() * 900 - 450)}deg`,
                      '--dur': `${1700 + Math.round(Math.random() * 1500)}ms`,
                      '--delay': `${Math.round(Math.random() * 700)}ms`,
                  },
              };
          });
    shownRank.value = after.value.rank;
    rankUpOpen.value = true;
    play('rank');
    nextTick(() => continueButton.value?.focus());
}

function closeRankUp() {
    rankUpOpen.value = false;
    emit('done');
}

function finish() {
    running = false;
    finished.value = true;
    const before = standing(props.reward.xpBefore, xpRules.value);
    if (after.value.rank > before.rank) {
        timers.push(setTimeout(openRankUp, reducedMotion ? 0 : 900));
    } else {
        emit('done');
    }
}

async function run() {
    stop();
    const reward = props.reward;
    if (!reward) return;
    const id = runId;
    const items = reward.items ?? [];

    shownXp.value = reward.xpBefore;
    shownRank.value = standing(reward.xpBefore, xpRules.value).rank;
    counted.value = 0;
    revealed.value = 0;
    levelNote.value = null;
    finished.value = false;
    rankUpOpen.value = false;

    if (reducedMotion) {
        shownXp.value = reward.xpAfter;
        counted.value = reward.total;
        revealed.value = items.length;
        finish();
        return;
    }

    running = true;
    await wait(500);
    let xp = reward.xpBefore;
    let total = 0;
    for (let i = 0; i < items.length; i++) {
        if (id !== runId) return;
        const item = items[i];
        revealed.value = i + 1;
        if (item.xp > 0) {
            play('points', 1 + i * 0.12); /* each line a little higher */
            const fromXp = xp;
            const fromTotal = total;
            await tween(460, (u) => {
                shownXp.value = fromXp + item.xp * u;
                counted.value = Math.round(fromTotal + item.xp * u);
            });
            if (id !== runId) return;
            xp += item.xp;
            total += item.xp;
        }
        await wait(240);
    }
    if (id !== runId) return;
    shownXp.value = reward.xpAfter;
    counted.value = reward.total;
    finish();
}

/* On a phone the card sits below the board, so it may be off screen when
   the fire is contained. The page is never scrolled for the player (nothing
   may move on its own); instead the counting waits until the card has come
   into view, so it is never missed. */
const card = ref(null);
const seen = ref(false);
let observer = null;

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined' || !card.value) {
        seen.value = true;
        return;
    }
    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                seen.value = true;
                observer.disconnect();
            }
        },
        { threshold: 0.6 },
    );
    observer.observe(card.value);
});

watch(
    [() => props.reward, seen],
    ([reward, isSeen]) => {
        if (!reward) stop();
        else if (isSeen) run();
        else {
            /* not in view yet: show where the player stood, ready to count */
            shownXp.value = reward.xpBefore;
            shownRank.value = standing(reward.xpBefore, xpRules.value).rank;
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    observer?.disconnect();
    stop();
});

const summary = computed(() => {
    if (!finished.value || !after.value) return '';
    return t('progress.reward.summary', { points: props.reward.total, level: after.value.level, rank: rankName(after.value.rank) });
});
const rankUpColour = computed(() => heatColor(rankStep(shownRank.value, xpRules.value), isDark.value));
</script>

<template>
    <div ref="card" class="bf-xp-card" :aria-busy="reward ? 'false' : 'true'">
        <template v-if="reward">
            <div class="bf-xp-head">
                <RankBadge :rank="shownRank" :rules="xpRules" :size="48" />
                <div class="bf-xp-who">
                    <span :key="bumpKey" class="bf-xp-level" :class="{ 'is-bump': bumpKey > 0 }">{{ t('progress.levelTitle', { level: now.level }) }}</span>
                    <span class="bf-xp-rank">{{ rankName(shownRank) }}</span>
                </div>
                <div class="bf-xp-total" aria-hidden="true">
                    <b>+{{ counted }}</b>
                    <small>{{ t('progress.reward.points') }}</small>
                </div>
            </div>

            <div class="bf-xp-bar" aria-hidden="true">
                <div :key="bumpKey" class="bf-xp-track" :class="{ 'is-flash': bumpKey > 0 }">
                    <div class="bf-xp-fill" :style="{ width: barPct + '%' }"></div>
                </div>
                <span class="bf-xp-sparks"><i v-for="s in sparks" :key="s.id" :style="s.style"></i></span>
            </div>

            <p class="bf-xp-note" :class="{ 'is-level': levelNote }" aria-hidden="true">
                <template v-if="levelNote">{{ t('progress.reward.levelUp', { level: levelNote }) }}</template>
                <template v-else>{{ t('progress.reward.toNext', { toGo: Math.ceil(now.toGo), level: now.level + 1 }) }}</template>
            </p>

            <ul class="bf-xp-lines" aria-hidden="true">
                <li v-for="(item, i) in reward.items" :key="item.key" :class="{ 'is-on': i < revealed }">
                    <span>{{ lineLabel(item) }}</span>
                    <b>+{{ item.xp }}</b>
                </li>
            </ul>
            <p v-if="allHinted" class="bf-help m-0">{{ t('progress.reward.noPoints') }}</p>

            <!-- one plain sentence for screen readers, once the counting is done -->
            <p class="sr-only" role="status">{{ summary }}</p>
        </template>

        <Teleport to="body">
            <div
                v-if="rankUpOpen"
                class="bf-rankup"
                role="dialog"
                aria-modal="true"
                aria-labelledby="bf-rankup-title"
                @keydown.esc="closeRankUp"
                @keydown.tab.prevent
                @click.self="closeRankUp"
            >
                <div class="bf-rankup-card" :style="{ '--ring': rankUpColour }">
                    <span class="bf-eyebrow">{{ t('progress.rankUp.eyebrow') }}</span>
                    <div class="bf-rankup-badge">
                        <span class="bf-rankup-ring"></span>
                        <span class="bf-rankup-ring is-late"></span>
                        <RankBadge :rank="shownRank" :rules="xpRules" :size="112" />
                    </div>
                    <h2 id="bf-rankup-title">{{ rankName(shownRank) }}</h2>
                    <p>{{ t('progress.rankUp.title', { rank: rankName(shownRank) }) }}</p>
                    <p class="bf-rankup-what">{{ t(`progress.rankUp.what.${xpRules.ranks[shownRank]}`) }}</p>
                    <button ref="continueButton" type="button" class="bf-btn bf-btn-primary bf-btn-lg mt-2 w-full" @click="closeRankUp">
                        {{ t('progress.rankUp.continue') }}
                    </button>
                </div>
                <div class="bf-rankup-confetti" aria-hidden="true"><i v-for="c in confetti" :key="c.id" :style="c.style"></i></div>
            </div>
        </Teleport>
    </div>
</template>
