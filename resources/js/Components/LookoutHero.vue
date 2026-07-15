<script setup>
import FlameGlyph from '@/Components/FlameGlyph.vue';
</script>

<template>
    <section class="bf-lookout-hero" aria-labelledby="burnfront-title">
        <img
            src="/artwork/burnfront-lookout-hero-v2.png"
            alt="Wildland field crews run toward a fire lookout tower across a recently contained burn scar."
            class="bf-lookout-art"
            width="1774"
            height="887"
            fetchpriority="high"
        />
        <div class="bf-lookout-shade" aria-hidden="true"></div>
        <span class="bf-lookout-corners" aria-hidden="true"></span>

        <div class="bf-lookout-topline">
            <span>North ridge &middot; lookout 08</span>
            <span class="bf-lookout-live"><i aria-hidden="true"></i> field teams active</span>
        </div>

        <div class="bf-lookout-copy">
            <p class="bf-lookout-kicker">Line Verification Unit &middot; Incident Desk</p>
            <h1 id="burnfront-title" class="bf-lookout-title">
                Burnfront<FlameGlyph glow class="h-[.74em] w-[.58em]" />
            </h1>
            <p class="bf-lookout-deck">
                The fire is out. Use the arrival times to place exactly N firebreaks. Every incident has one solution.
            </p>
            <ol class="bf-lookout-protocol" aria-label="How to play Burnfront">
                <li><span>01</span><strong>Read arrival times</strong></li>
                <li><span>02</span><strong>Mark exactly N breaks</strong></li>
                <li><span>03</span><strong>Match every minute</strong></li>
            </ol>
        </div>

        <div class="bf-lookout-readout" aria-hidden="true">
            <span>Status</span>
            <strong>Contained</strong>
            <span>3 crews logged</span>
        </div>
    </section>
</template>
