<script setup>
/* The Start-screen hero: a looping fire-lookout scene. Ember-dusk sky, layered
   ridge silhouettes, a timber lookout tower with a lit cabin window, drifting
   haze, rising embers, and a small figure that runs in, climbs the tower and
   sweeps a sightline beam across the horizon before the loop resets.

   Ported from the design handoff prototype's initLookout() (same easing,
   timeline fractions and pose math), rewritten against Vue refs instead of
   getElementById. Reduced-motion settles on a single "at the catwatch,
   looking out" frame instead of running the loop. */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const runner = ref(null);
const legF = ref(null);
const legB = ref(null);
const armF = ref(null);
const armB = ref(null);
const head = ref(null);
const beamG = ref(null);
const beam = ref(null);

const START = { x: 70, y: 311 };
const BASE = { x: 452, y: 303 };
const TOP = { x: 476, y: 150 };
const PERIOD = 9200;

const lerp = (a, b, t) => a + (b - a) * t;
const smooth = (t) => t * t * (3 - 2 * t);
const set = (el, tr) => el && el.setAttribute('transform', tr);

let rafId = null;

function pose(x, y, sx, mode, cad, lookP) {
    set(runner.value, `translate(${x.toFixed(1)} ${y.toFixed(1)}) scale(${sx} 1)`);
    if (mode === 'run') {
        const s = Math.sin(cad);
        set(legF.value, `rotate(${s * 36} 0 -15)`);
        set(legB.value, `rotate(${-s * 36} 0 -15)`);
        set(armF.value, `rotate(${-s * 46} 0 -28)`);
        set(armB.value, `rotate(${s * 46} 0 -28)`);
        set(head.value, 'rotate(7 0 -33)');
        beamG.value?.setAttribute('opacity', '0');
    } else if (mode === 'climb') {
        const s = Math.sin(cad * 1.1);
        set(legF.value, `rotate(${28 + s * 16} 0 -15)`);
        set(legB.value, `rotate(${-28 - s * 16} 0 -15)`);
        set(armF.value, `rotate(${-134 + s * 18} 0 -28)`);
        set(armB.value, `rotate(${-104 - s * 18} 0 -28)`);
        set(head.value, 'rotate(-4 0 -33)');
        beamG.value?.setAttribute('opacity', '0');
    } else {
        set(legF.value, 'rotate(8 0 -15)');
        set(legB.value, 'rotate(-8 0 -15)');
        set(armB.value, 'rotate(26 0 -28)');
        set(armF.value, 'rotate(-150 0 -28)');
        set(head.value, `rotate(${Math.sin(lookP * Math.PI * 3) * 15} 0 -33)`);
        const ba = Math.sin(lookP * Math.PI * 2 - Math.PI / 2) * 30;
        set(beam.value, `rotate(${ba} 486 120)`);
        const bo = Math.max(0, Math.min(1, Math.min(lookP, 1 - lookP) * 5)) * 0.5;
        beamG.value?.setAttribute('opacity', bo.toFixed(2));
    }
}

function frame(ts, t0) {
    const el = ts - t0;
    const p = (el % PERIOD) / PERIOD;
    const cad = el / 95;
    let op = 1;
    if (p < 0.02) op = 0;
    else if (p < 0.05) op = (p - 0.02) / 0.03;
    else if (p < 0.9) op = 1;
    else if (p < 0.95) op = 1 - (p - 0.9) / 0.05;
    else op = 0;
    runner.value?.setAttribute('opacity', op.toFixed(2));

    if (p < 0.34) {
        const q = smooth(Math.min(1, Math.max(0, (p - 0.03) / 0.31)));
        pose(lerp(START.x, BASE.x, q), lerp(START.y, BASE.y, q) - Math.sin(q * Math.PI) * 4, 1, 'run', cad, 0);
    } else if (p < 0.55) {
        const q = smooth((p - 0.34) / 0.21);
        pose(lerp(BASE.x, TOP.x, q), lerp(BASE.y, TOP.y, q), 1, 'climb', cad, 0);
    } else {
        const lp = Math.max(0, Math.min(1, (p - 0.55) / 0.35));
        pose(TOP.x, TOP.y, 1, 'look', cad, lp);
    }
    rafId = requestAnimationFrame((next) => frame(next, t0));
}

onMounted(() => {
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (reduced) {
        runner.value?.setAttribute('opacity', '1');
        pose(TOP.x, TOP.y, 1, 'look', 0, 0.25);
        set(beam.value, 'rotate(-6 486 120)');
        beamG.value?.setAttribute('opacity', '0.4');
        return;
    }
    rafId = requestAnimationFrame((ts) => frame(ts, ts));
});

onBeforeUnmount(() => {
    if (rafId) cancelAnimationFrame(rafId);
});
</script>

<template>
    <svg viewBox="0 0 700 424" preserveAspectRatio="xMidYMid slice" class="block h-full w-full">
        <defs>
            <linearGradient id="lk-sky" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#150e0b" />
                <stop offset="0.5" stop-color="#1b120d" />
                <stop offset="0.78" stop-color="#3a1d0e" />
                <stop offset="1" stop-color="#602c11" />
            </linearGradient>
            <radialGradient id="lk-sun" cx="0.5" cy="0.5" r="0.5">
                <stop offset="0" stop-color="#ffc06a" stop-opacity="0.95" />
                <stop offset="0.45" stop-color="#ff7a2d" stop-opacity="0.45" />
                <stop offset="1" stop-color="#ff7a2d" stop-opacity="0" />
            </radialGradient>
            <linearGradient id="lk-beam" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#ffd980" stop-opacity="0.55" />
                <stop offset="1" stop-color="#ffd980" stop-opacity="0" />
            </linearGradient>
            <radialGradient id="lk-win" cx="0.5" cy="0.5" r="0.7">
                <stop offset="0" stop-color="#ffe4a0" />
                <stop offset="1" stop-color="#ff8a2d" />
            </radialGradient>
            <filter id="lk-blur" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="7" /></filter>
            <filter id="lk-blur2" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="2.4" /></filter>
        </defs>

        <rect x="0" y="0" width="700" height="424" fill="url(#lk-sky)" />
        <ellipse cx="502" cy="252" rx="250" ry="150" fill="url(#lk-sun)" />
        <circle cx="506" cy="240" r="46" fill="#ffbf68" opacity="0.5" filter="url(#lk-blur)" />
        <g>
            <ellipse class="lk-haze" cx="230" cy="250" rx="150" ry="15" fill="#6a3a1c" opacity="0.38" filter="url(#lk-blur)" />
            <ellipse class="lk-haze2" cx="470" cy="278" rx="210" ry="13" fill="#7a3f1c" opacity="0.4" filter="url(#lk-blur)" />
        </g>
        <path d="M0,262 L130,250 L250,266 L360,240 L470,262 L580,244 L700,266 L700,424 L0,424 Z" fill="#241812" />
        <path d="M0,262 L130,250 L250,266 L360,240 L470,262 L580,244 L700,266" fill="none" stroke="#8a4018" stroke-width="1.5" opacity="0.6" />
        <path d="M0,290 L150,280 L300,294 L440,272 L560,290 L700,280 L700,424 L0,424 Z" fill="#180f0a" />
        <ellipse cx="476" cy="306" rx="74" ry="9" fill="#000000" opacity="0.4" filter="url(#lk-blur2)" />
        <path d="M0,360 L0,312 C150,306 320,300 476,302 C560,303 640,308 700,312 L700,424 L0,424 Z" fill="#0e0906" />
        <path d="M0,312 C150,306 320,300 476,302 C560,303 640,308 700,312" fill="none" stroke="#3a2213" stroke-width="1.5" opacity="0.55" />
        <g fill="#ff9a4d">
            <circle class="lk-ember" cx="432" cy="300" r="2" style="animation-delay: 0s" />
            <circle class="lk-ember" cx="502" cy="308" r="1.5" style="animation-delay: 1.4s" />
            <circle class="lk-ember" cx="470" cy="292" r="1.8" style="animation-delay: 2.7s" />
            <circle class="lk-ember" cx="544" cy="300" r="1.3" style="animation-delay: 3.7s" />
            <circle class="lk-ember" cx="410" cy="306" r="1.6" style="animation-delay: 4.9s" />
        </g>
        <g stroke-linecap="round">
            <path d="M462,150 L455,302 M490,150 L497,302" stroke="#241710" stroke-width="4" />
            <g stroke="#2c1d12" stroke-width="2.5"><path d="M446,198 L508,232 M508,198 L446,232 M442,244 L512,284 M512,244 L442,284" /></g>
            <path d="M452,150 L437,302 M500,150 L515,302" stroke="#150e09" stroke-width="5.5" />
            <rect x="441" y="146" width="70" height="7" rx="1.5" fill="#150e09" />
            <g stroke="#150e09" stroke-width="2"><path d="M444,146 L444,131 M462,146 L462,131 M490,146 L490,131 M508,146 L508,131 M441,131 L511,131" /></g>
            <rect x="450" y="102" width="52" height="45" rx="2" fill="#1c130d" />
            <rect x="461" y="114" width="20" height="20" rx="1.5" fill="url(#lk-win)" />
            <rect x="459" y="112" width="24" height="24" rx="2" fill="#ffce8f" opacity="0.35" filter="url(#lk-blur2)" />
            <path d="M443,103 L509,103 L476,78 Z" fill="#150e09" />
            <path d="M476,78 L476,68" stroke="#150e09" stroke-width="2.5" />
        </g>
        <g ref="beamG" opacity="0"><path ref="beam" d="M486,120 L700,90 L700,150 Z" fill="url(#lk-beam)" filter="url(#lk-blur2)" /></g>
        <g
            ref="runner"
            transform="translate(70 311)"
            opacity="0"
            fill="none"
            stroke="#ffb055"
            stroke-width="3"
            stroke-linecap="round"
            style="filter: drop-shadow(0 0 3px rgba(255, 150, 70, 0.55))"
        >
            <g ref="legB" transform="rotate(0 0 -15)"><path d="M0,-15 L-1,0" /></g>
            <g ref="armB" transform="rotate(0 0 -28)"><path d="M0,-28 L-3,-18" /></g>
            <path d="M0,-15 L0,-29" />
            <g ref="armF" transform="rotate(0 0 -28)"><path d="M0,-28 L3,-18" /></g>
            <g ref="legF" transform="rotate(0 0 -15)"><path d="M0,-15 L2,0" /></g>
            <g ref="head" transform="rotate(0 0 -33)">
                <circle cx="0" cy="-33" r="4.2" fill="#ffb055" stroke="none" />
                <path d="M0,-33 L6,-34" stroke="#ffb055" stroke-width="2.5" />
            </g>
        </g>
    </svg>
</template>

<style scoped>
@keyframes lk-rise {
    0% {
        transform: translateY(0);
        opacity: 0;
    }
    12% {
        opacity: 0.9;
    }
    100% {
        transform: translateY(-48px);
        opacity: 0;
    }
}
.lk-ember {
    transform-box: fill-box;
    transform-origin: center;
    animation: lk-rise 5.5s linear infinite;
}
@keyframes lk-drift {
    from {
        transform: translateX(-14px);
    }
    to {
        transform: translateX(22px);
    }
}
.lk-haze {
    transform-box: fill-box;
    animation: lk-drift 26s ease-in-out infinite alternate;
}
.lk-haze2 {
    transform-box: fill-box;
    animation: lk-drift 34s ease-in-out infinite alternate-reverse;
}
@media (prefers-reduced-motion: reduce) {
    .lk-ember,
    .lk-haze,
    .lk-haze2 {
        animation: none !important;
    }
    .lk-ember {
        opacity: 0.55 !important;
    }
}
</style>
