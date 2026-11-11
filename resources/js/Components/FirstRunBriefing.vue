<script setup>
/* The welcome screen for a first visit. It covers the home page until the
   player either starts the lesson or says they already know how to play;
   either choice is remembered on this device (lib/burnfront-onboarding.js),
   so it shows once. No account and no clock: the lesson comes first. */
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { t } from '@/lib/i18n';
import { heatCell } from '@/lib/heat';
import { completeOnboarding, hasCompletedOnboarding } from '@/lib/burnfront-onboarding';
import { LESSON, burn, emptyMarks } from '@/lib/burnfront-lesson';

const visible = ref(!hasCompletedOnboarding());
const emit = defineEmits(['visibility']);

/* The lesson puzzle, solved, as a small picture of what the game looks like. */
const picture = computed(() => {
    const marks = emptyMarks();
    marks[9] = 1;
    marks[13] = 1;
    const dist = burn(marks);
    return dist.map((d, i) => {
        if (d < 0) return { i, kind: 'break', label: '' };
        const colours = heatCell(d, LESSON.maxMinute);
        if (d === 0) return { i, kind: 'spark', label: '', ...colours };
        return { i, kind: LESSON.clues[i] != null ? 'clue' : 'burnt', label: String(d), ...colours };
    });
});

const dialog = ref(null);
const primary = ref(null);

/* While the welcome covers the home page, the page behind it is scrolled
   shut and made inert, so Tab and screen readers only reach the two visible
   choices; focus starts on "Show me how". */
function isolate(on) {
    if (typeof document === 'undefined') return;
    document.documentElement.style.overflow = on ? 'hidden' : '';
    for (const el of document.body.children) {
        if (el === dialog.value) continue;
        if (on) el.setAttribute('inert', '');
        else el.removeAttribute('inert');
    }
    if (on) nextTick(() => (primary.value?.$el ?? primary.value)?.focus());
}

watch(visible, (v) => nextTick(() => isolate(v)));

onMounted(() => {
    emit('visibility', visible.value);
    nextTick(() => isolate(visible.value));
});

onBeforeUnmount(() => isolate(false));

function dismiss() {
    completeOnboarding();
    visible.value = false;
    emit('visibility', false);
}
</script>

<template>
    <Teleport to="body">
        <section v-if="visible" ref="dialog" class="bf-welcome" role="dialog" aria-modal="true" aria-labelledby="bf-welcome-title">
            <div class="bf-welcome-inner">
                <header class="bf-welcome-brand">
                    <svg aria-hidden="true" width="30" height="30" viewBox="0 0 60 60">
                        <clipPath id="bf-welcome-logo"><rect width="60" height="60" rx="9" /></clipPath>
                        <g clip-path="url(#bf-welcome-logo)">
                            <rect width="60" height="60" fill="#CC2162" />
                            <rect x="0" y="20" width="40" height="40" fill="#FF7A1F" />
                            <rect x="0" y="40" width="20" height="20" fill="#FFE24A" />
                        </g>
                    </svg>
                    <span class="bf-welcome-wordmark">burnfront</span>
                </header>

                <div class="bf-welcome-copy">
                    <h1 id="bf-welcome-title">{{ t('lesson.welcome.title') }}</h1>
                    <p>{{ t('lesson.welcome.intro') }}</p>
                </div>

                <div class="bf-welcome-picture" role="img" :aria-label="t('lesson.welcome.pictureLabel')">
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

                <div class="bf-welcome-actions">
                    <Link ref="primary" href="/learn" class="bf-welcome-primary">
                        <span>{{ t('lesson.welcome.start') }}</span>
                        <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#FFE24A" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </Link>
                    <button type="button" class="bf-welcome-secondary" @click="dismiss">{{ t('lesson.welcome.skip') }}</button>
                    <p class="bf-welcome-note">{{ t('lesson.welcome.note') }}</p>
                </div>
            </div>
        </section>
    </Teleport>
</template>

<style scoped>
.bf-welcome {
    --w-ground: var(--bf-ground, #f1f1ee);
    --w-ink: var(--bf-ink, #16181b);
    --w-muted: var(--bf-muted, #55585e);
    --w-break: var(--bf-break, #16181b);
    position: fixed;
    inset: 0;
    z-index: 50;
    overflow-y: auto;
    background: var(--w-ground);
    color: var(--w-ink);
    font-family: Geist, 'Instrument Sans', system-ui, sans-serif;
}
.bf-welcome-inner {
    box-sizing: border-box;
    max-width: 440px;
    min-height: 100%;
    margin: 0 auto;
    padding: 16px 16px max(24px, env(safe-area-inset-bottom));
    display: flex;
    flex-direction: column;
}
.bf-welcome-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 44px;
}
.bf-welcome-wordmark {
    font-family: 'Bricolage Grotesque', system-ui, sans-serif;
    font-weight: 800;
    font-size: 24px;
    letter-spacing: -0.035em;
}
.bf-welcome-copy {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 28px;
}
.bf-welcome-copy h1 {
    margin: 0;
    font-family: 'Bricolage Grotesque', system-ui, sans-serif;
    font-weight: 800;
    font-size: 44px;
    line-height: 0.98;
    letter-spacing: -0.04em;
    text-wrap: balance;
}
.bf-welcome-copy p {
    margin: 0;
    font-size: 20px;
    line-height: 1.4;
    color: var(--w-muted);
    text-wrap: pretty;
}
.bf-welcome-picture {
    margin: 28px auto 28px;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 5px;
    width: min(260px, 70vw);
}
.bf-welcome-picture span {
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 5px;
    font-family: 'Bricolage Grotesque', system-ui, sans-serif;
    font-weight: 700;
    font-size: 16px;
}
.bf-welcome-picture .is-clue {
    font-size: 28px;
}
.bf-welcome-picture .is-break {
    background: var(--w-break);
}
.bf-welcome-picture svg {
    width: 26px;
    height: 26px;
    fill: #16181b;
}
.bf-welcome-actions {
    margin-top: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.bf-welcome-primary {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    height: 68px;
    border-radius: 8px;
    background: var(--w-ink);
    color: var(--w-ground);
    text-decoration: none;
    font-family: 'Bricolage Grotesque', system-ui, sans-serif;
    font-weight: 700;
    font-size: 24px;
}
.bf-welcome-secondary {
    height: 60px;
    border: 2px solid var(--w-ink);
    border-radius: 8px;
    background: transparent;
    color: var(--w-ink);
    font-family: inherit;
    font-weight: 600;
    font-size: 18px;
    cursor: pointer;
}
.bf-welcome-primary:focus-visible,
.bf-welcome-secondary:focus-visible {
    outline: 3px solid var(--w-ink);
    outline-offset: 2px;
}
.bf-welcome-note {
    margin: 4px 0 0;
    text-align: center;
    font-size: 16px;
    color: var(--w-muted);
}
</style>
