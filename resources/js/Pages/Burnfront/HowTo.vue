<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import HowItWorksDemo from './HowItWorksDemo.vue';
import SiteBar from '@/Components/SiteBar.vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';
import { completeOnboarding, hasCompletedOnboarding } from '@/lib/burnfront-onboarding';

const trainingComplete = ref(hasCompletedOnboarding());

function finishTraining() {
    completeOnboarding();
    trainingComplete.value = true;
}
</script>

<template>
    <Head title="How Burnfront works" />

    <main class="mx-auto flex max-w-[640px] flex-col gap-7 px-4 pt-3 pb-12 sm:pt-5 sm:pb-16">
        <SiteBar :back="{ href: '/', text: 'Menu' }" />

        <section class="flex flex-col gap-4" aria-label="How Burnfront works">
            <div class="flex flex-col gap-1.5">
                <p class="bf-eyebrow">First deployment · Guided training</p>
                <h1 class="font-staatliches text-[clamp(40px,9vw,56px)] leading-[0.95] font-normal tracking-[.035em] text-stock">
                    FIELD BRIEFING
                </h1>
                <p class="max-w-[56ch] text-[14px] leading-[1.5] text-ash">
                    Work through five short steps at your own pace, then reinforce them on the easiest live board.
                </p>
            </div>
            <div>
                <HowItWorksDemo @complete="finishTraining" />
            </div>
            <details class="bf-reference-manual">
                <summary>Open reference manual <span>Detailed rules</span></summary>
                <div class="bf-rulecols">
                    <div class="flex flex-col gap-2.5 bf-rulecol">
                        <h3>The rules</h3>
                        <ol>
                            <li><strong>Shade exactly N firebreaks.</strong> The counter above the board sets N. The <FlameGlyph class="inline h-[1em] w-[.75em]" /><span class="sr-only">flame</span> and the numbered cells are never breaks.</li>
                            <li><strong>Fire spreads one cell per minute.</strong> It starts on the <FlameGlyph class="inline h-[1em] w-[.75em]" /><span class="sr-only">flame</span> at minute 0 and moves up, down, left and right — never diagonally, never through a break.</li>
                            <li><strong>Everything else burns.</strong> Every cell that isn&rsquo;t a firebreak must be reached by the fire eventually. No safe pockets.</li>
                            <li><strong>Numbers are exact arrival times.</strong> A cell marked 5 caught fire at minute 5 — not before, not after.</li>
                        </ol>
                    </div>
                    <div class="flex flex-col gap-2.5 bf-rulecol">
                        <h3>Reading the numbers</h3>
                        <ul>
                            <li>A cell&rsquo;s minute is the length of the fire&rsquo;s <strong>shortest open route</strong> from the <FlameGlyph class="inline h-[1em] w-[.75em]" /><span class="sr-only">flame</span> — never less than the straight-line distance.</li>
                            <li><strong>Bigger than the distance? Something is in the way.</strong> A 5 sitting 3 steps from the <FlameGlyph class="inline h-[1em] w-[.75em]" /><span class="sr-only">flame</span> proves every shorter route is blocked. That is how numbers reveal breaks.</li>
                            <li>Neighboring burnt cells differ by at most 1, and a cell burning at minute t caught it from a neighbor that burned at t&minus;1 — wavefronts, not teleports.</li>
                            <li><strong>Every break earns its place:</strong> open it, and the fire would reach some number too early. None hides in a corner justified by counting alone.</li>
                            <li>Tap cycles firebreak &rarr; dot &rarr; empty. The dot is your own note for &ldquo;proven open&rdquo; — it isn&rsquo;t checked. The moment your Nth break lands, the board checks itself.</li>
                        </ul>
                    </div>
                </div>
            </details>
        </section>

        <footer class="flex flex-wrap gap-2.5">
            <Link href="/endless/play?difficulty=lookout" class="bf-btn bf-btn-primary">{{ trainingComplete ? 'Start practice case' : 'Skip to practice' }}</Link>
            <Link href="/" class="bf-btn">Back to menu</Link>
        </footer>
    </main>
</template>
