<script setup>
/* Live WebGL low-poly heightmap for the solve payoff, driven by the ACTUAL
   solved incident rather than a fixed scene: the real R×C grid, the real spark
   cell, firebreaks cut as dark trenches exactly where the player placed them,
   and every burnt cube glowing at a temperature keyed to its true BFS arrival
   minute. On mount the fire ignites outward from the spark in wavefront order
   (mirroring the board's own staggered burn replay) before settling into a
   gentle idle orbit. Camera bearing, ridge shape and ember temperature are
   seeded per incident, so two different incidents never present the same — and
   the same incident always reads identically. Reduced-motion holds a single
   fully-burnt frame at the seeded angle.

   Ported from the design handoff prototype's initRidge() (same terrain formula,
   palette family and camera rig), rewritten as a data-driven Vue component with
   proper WebGL teardown — Play.vue mounts/unmounts this every time a board is
   solved, so a leaked renderer/context (or a leaked ignition rAF) per solve
   would exhaust the browser's concurrent-WebGL-context limit over a long
   session. */
import * as THREE from 'three';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    rows: { type: Number, required: true },
    cols: { type: Number, required: true },
    spark: { type: Number, required: true },
    /* Per-cell burn arrival minute, straight off the engine's validate():
       times[i] >= 0 is the minute the fire reached cell i; times[i] < 0 marks a
       firebreak. On a solved board (which is the only board this hero ever sees)
       the set of times[i] < 0 is exactly the firebreaks — no separate breaks
       list is needed. Cells are linear, row-major: row = ⌊i/cols⌋, col = i%cols. */
    times: { type: Array, default: () => [] },
});

const canvas = ref(null);
let renderer = null;
let observer = null;
let rafId = null;

/* Small deterministic PRNG (mulberry32): a given incident always seeds the same
   camera bearing / ridge shape / ember temperature, different incidents differ. */
function mulberry32(a) {
    return function () {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function buildScene(w, h) {
    const R = props.rows,
        C = props.cols,
        n = R * C;
    const times = props.times;

    const scene = new THREE.Scene();

    /* Nothing sane to draw without a per-cell time array that matches the grid
       — return an empty (but renderable) scene rather than a half-built one.
       In practice the hero only ever mounts once burnTimes is populated for the
       current board, so this is a safety net, not a normal path. */
    if (!Number.isFinite(n) || n <= 0 || !times || times.length !== n) {
        const cam = new THREE.OrthographicCamera(-1, 1, 1, -1, -80, 160);
        return { scene, cam, group: new THREE.Group(), geom: null, meshes: [], igniters: [], emberL: null, d: 1, reduced: true, igniteEnds: 0 };
    }

    const aspect = w / h;
    /* Frame the grid to fill the hero regardless of size; the constant pad
       covers absolute cube heights (which don't scale with the grid), so a
       small grid's relatively taller cubes never clip the frustum as the group
       orbits. At a 10×10 grid this lands near the original hardcoded d=6.4. */
    const d = 0.64 * Math.max(R, C) + 0.6;

    const cam = new THREE.OrthographicCamera(-d * aspect, d * aspect, d, -d, -80, 160);
    cam.position.set(9, 10.5, 9);
    cam.lookAt(0, 0.4, 0);
    scene.add(new THREE.AmbientLight(0xffe9d5, 0.62));
    const key = new THREE.DirectionalLight(0xfff0dc, 0.82);
    key.position.set(7, 15, 5);
    scene.add(key);
    const rim = new THREE.DirectionalLight(0x6f97a0, 0.34);
    rim.position.set(-9, 5, -7);
    scene.add(rim);

    const group = new THREE.Group();
    scene.add(group);

    /* Per-incident seed: fold grid, spark and the full arrival-time field into
       one hash. Two incidents that share a grid + spark but differ in firebreaks
       have different arrival times, so they still seed apart. */
    let seed = (R * 73856093) ^ (C * 19349663) ^ ((props.spark + 1) * 83492791);
    for (let k = 0; k < n; k++) seed = (seed * 31 + (times[k] | 0)) | 0;
    const rand = mulberry32(Math.abs(seed) || 1);

    const bearing = rand() * Math.PI * 2; /* seeded compass angle the scene opens from */
    const phaseX = rand() * Math.PI * 2; /* seeded ridge undulation, so terrain shape varies per case */
    const phaseZ = rand() * Math.PI * 2;
    const temp = rand(); /* 0 → cooler (yellower), 1 → hotter (deeper orange) ember cast */
    group.rotation.y = bearing;

    const halfR = (R - 1) / 2,
        halfC = (C - 1) / 2;

    let maxT = 0;
    for (let k = 0; k < n; k++) if (times[k] > maxT) maxT = times[k];
    maxT = maxT || 1; /* guard the t/maxT normalization on a degenerate board */

    /* Ignition pacing mirrors win()'s board replay: the wavefront reaches the
       last cell (maxT) in at most ~1.4s, per-step capped. */
    const step = Math.min(140, 1400 / maxT);
    const igniteDur = 320; /* per-cell ramp from cold ground to ember */
    const igniteEnds = maxT * step + igniteDur;

    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    const emberL = new THREE.PointLight(0xff7a2d, reduced ? 2.0 : 0.5, 24);
    const geom = new THREE.BoxGeometry(0.9, 1, 0.9);
    const cW = new THREE.Color(0x2a2018),
        cWhi = new THREE.Color(0x3c2f22);
    /* Hot ember endpoint, nudged per incident but kept inside the ember family. */
    const cHot = new THREE.Color(0xff8a2d).offsetHSL((temp - 0.5) * 0.04, 0, (temp - 0.5) * 0.1);
    const cCold = new THREE.Color(0x5a1e0e);
    const black = new THREE.Color(0x000000);
    const meshes = [];
    const igniters = []; /* burnt cells that animate cold-ground → ember on mount */

    for (let idx = 0; idx < n; idx++) {
        const row = Math.floor(idx / C),
            col = idx % C;
        const nx = R > 1 ? row / (R - 1) : 0.5;
        const nz = C > 1 ? col / (C - 1) : 0.5;
        let hgt = 1.5 * Math.exp(-Math.pow((row - col + 1.6) / 2.6, 2));
        hgt += 0.35 * Math.sin(nx * 6.1 + phaseX) * Math.cos(nz * 5.2 + phaseZ);
        hgt += 0.55;
        if (hgt < 0.3) hgt = 0.3;

        const isBreak = times[idx] < 0;
        const isSpark = idx === props.spark;

        /* Cold, un-burnt ground tone for this cell — the pre-ignition look of a
           burnt cell, and the resting look no cell keeps on a solved board. */
        const unlit = cW.clone().lerp(cWhi, Math.min(1, hgt / 2.2));

        /* Final, settled appearance once the fire has passed. */
        let finalColor,
            finalEmissive = black.clone(),
            finalEInt = 0;
        if (isBreak) {
            hgt = Math.max(0.26, hgt - 0.55); /* cut the trench below the terrain */
            finalColor = new THREE.Color(0x120f0d);
            finalEmissive = new THREE.Color(0x14232a);
            finalEInt = 0.3;
        } else if (isSpark) {
            finalColor = new THREE.Color(0xffb055);
            finalEmissive = new THREE.Color(0xff7a2d);
            finalEInt = 1.15;
        } else {
            const t = Math.min(1, times[idx] / maxT); /* flame core early, deep ember late */
            finalColor = cHot.clone().lerp(cCold, t);
            finalEmissive = new THREE.Color(0xff5a1e);
            finalEInt = 0.5 * (1 - t) + 0.12;
        }

        /* Only the burnt cells animate; the spark is lit from the first frame
           (it's the origin) and firebreaks never catch. Under reduced-motion
           everything is built already-settled. */
        const animate = !reduced && !isBreak && !isSpark;

        const mat = new THREE.MeshStandardMaterial({
            color: (animate ? unlit : finalColor).clone(),
            emissive: (animate ? black : finalEmissive).clone(),
            emissiveIntensity: animate ? 0 : finalEInt,
            roughness: 0.88,
            metalness: 0.04,
            flatShading: true,
        });
        const m = new THREE.Mesh(geom, mat);
        m.scale.y = hgt;
        m.position.set(col - halfC, hgt / 2, row - halfR);
        group.add(m);
        meshes.push(m);

        if (isSpark) emberL.position.set(col - halfC, hgt + 1.4, row - halfR);

        if (animate) {
            igniters.push({
                mat,
                igniteAt: times[idx] * step,
                dur: igniteDur,
                c0: unlit.clone(),
                c1: finalColor.clone(),
                e1: finalEmissive.clone(),
                i1: finalEInt,
                done: false,
            });
        }
    }
    group.add(emberL);

    return { scene, cam, group, geom, meshes, igniters, emberL, d, reduced, igniteEnds };
}

onMounted(() => {
    const el = canvas.value;
    if (!el) return;
    let w = el.clientWidth || 340,
        h = el.clientHeight || 200;

    renderer = new THREE.WebGLRenderer({ canvas: el, antialias: true, alpha: true, preserveDrawingBuffer: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setSize(w, h, false);

    const built = buildScene(w, h);
    const render = () => renderer.render(built.scene, built.cam);
    const black = new THREE.Color(0x000000);

    if (built.reduced) {
        render();
    } else {
        const start = performance.now();
        const loop = () => {
            const elapsed = performance.now() - start;
            built.group.rotation.y += 0.0026;

            /* Advance the wavefront: each burnt cell eases cold-ground → ember
               over its own window, starting at its arrival minute. */
            for (const ig of built.igniters) {
                if (ig.done) continue;
                const p = Math.min(1, Math.max(0, (elapsed - ig.igniteAt) / ig.dur));
                const e = p * p * (3 - 2 * p); /* smoothstep */
                ig.mat.color.copy(ig.c0).lerp(ig.c1, e);
                ig.mat.emissive.copy(black).lerp(ig.e1, e);
                ig.mat.emissiveIntensity = ig.i1 * e;
                if (p >= 1) ig.done = true;
            }
            if (built.emberL) built.emberL.intensity = 0.5 + 1.5 * Math.min(1, elapsed / (built.igniteEnds || 1));

            render();
            rafId = requestAnimationFrame(loop);
        };
        loop();
    }

    if ('ResizeObserver' in window) {
        observer = new ResizeObserver(() => {
            const nw = el.clientWidth,
                nh = el.clientHeight;
            if (!nw || !nh || (nw === w && nh === h)) return;
            w = nw;
            h = nh;
            renderer.setSize(w, h, false);
            const aspect = w / h,
                d = built.d;
            built.cam.left = -d * aspect;
            built.cam.right = d * aspect;
            built.cam.top = d;
            built.cam.bottom = -d;
            built.cam.updateProjectionMatrix();
            render();
        });
        observer.observe(el);
    }

    canvas.value.__ridge = built;
});

onBeforeUnmount(() => {
    if (rafId) cancelAnimationFrame(rafId);
    observer?.disconnect();
    const built = canvas.value?.__ridge;
    if (built) {
        built.geom?.dispose();
        built.meshes.forEach((m) => m.material.dispose());
    }
    renderer?.dispose();
    renderer?.forceContextLoss?.();
    renderer = null;
});
</script>

<template>
    <canvas ref="canvas" class="block h-full w-full"></canvas>
</template>
