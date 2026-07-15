<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import ModeGlyph from '@/Components/ModeGlyph.vue';
import { completeOnboarding, hasCompletedOnboarding } from '@/lib/burnfront-onboarding';

const visible = ref(!hasCompletedOnboarding());
const emit = defineEmits(['visibility']);

onMounted(() => {
    emit('visibility', visible.value);
});

function dismiss() {
    completeOnboarding();
    visible.value = false;
    emit('visibility', false);
}
</script>

<template>
    <aside v-if="visible" class="bf-first-briefing" aria-labelledby="first-briefing-title">
        <span class="bf-first-briefing-mark" aria-hidden="true"><ModeGlyph mode="howto" /></span>
        <div class="bf-first-briefing-copy">
            <p class="bf-eyebrow">First shift · Training recommended</p>
            <h2 id="first-briefing-title">New to the incident desk?</h2>
            <p>A short guided briefing shows how timestamps expose hidden firebreaks, then sends you into an easy practice case.</p>
        </div>
        <div class="bf-first-briefing-actions">
            <Link href="/how-to" class="bf-btn bf-btn-primary">Start briefing</Link>
            <button type="button" class="bf-text-button" @click="dismiss">I know the rules</button>
        </div>
    </aside>
</template>
