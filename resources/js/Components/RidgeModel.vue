<script setup>
/* Live WebGL low-poly heightmap for the solve payoff: an orthographic iso
   camera over a terrain of cubes, burnt cells glowing ember (hotter near the
   spark), firebreaks cut as dark trenches, the spark as a point light. The
   group rotates gently; reduced-motion holds a single frame.

   Ported from the design handoff prototype's initRidge() (same terrain
   formula, palette and camera rig), rewritten as a Vue component with proper
   WebGL teardown — Play.vue mounts/unmounts this every time a board is
   solved, so a leaked renderer/context per solve would exhaust the browser's
   concurrent-WebGL-context limit over a long session. */
import * as THREE from 'three';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    burntRatio: { type: Number, default: 0.5 },
});

const canvas = ref(null);
let renderer = null;
let observer = null;
let rafId = null;

function buildScene(w, h) {
    const scene = new THREE.Scene();
    const aspect = w / h,
        d = 6.4;
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
    const N = 10,
        half = (N - 1) / 2;
    const sx = 4,
        sz = 5;
    const breaks = new Set(['3,6', '4,6', '5,6', '6,6', '6,5']);
    const maxR = Math.hypot(N, N) * 0.62;
    const emberL = new THREE.PointLight(0xff7a2d, 1.5 * (0.5 + props.burntRatio), 24);
    const geom = new THREE.BoxGeometry(0.9, 1, 0.9);
    const cW = new THREE.Color(0x2a2018),
        cWhi = new THREE.Color(0x3c2f22);
    const cHot = new THREE.Color(0xff8a2d),
        cCold = new THREE.Color(0x5a1e0e);
    const meshes = [];

    for (let i = 0; i < N; i++) {
        for (let j = 0; j < N; j++) {
            const kk = i + ',' + j;
            const nx = i / (N - 1),
                nz = j / (N - 1);
            let hgt = 1.5 * Math.exp(-Math.pow((i - j + 1.6) / 2.6, 2));
            hgt += 0.35 * Math.sin(nx * 6.1) * Math.cos(nz * 5.2);
            hgt += 0.55;
            if (hgt < 0.3) hgt = 0.3;
            const isBreak = breaks.has(kk);
            const dist = Math.hypot(i - sx, j - sz);
            const isSpark = i === sx && j === sz;
            const isBurnt = !isBreak && dist <= props.burntRatio * maxR;
            let color,
                emissive = new THREE.Color(0x000000),
                eInt = 0;
            if (isBreak) {
                hgt = Math.max(0.26, hgt - 0.55);
                color = new THREE.Color(0x120f0d);
                emissive = new THREE.Color(0x14232a);
                eInt = 0.3;
            } else if (isSpark) {
                color = new THREE.Color(0xffb055);
                emissive = new THREE.Color(0xff7a2d);
                eInt = 1.15;
            } else if (isBurnt) {
                const t = Math.min(1, dist / maxR);
                color = cHot.clone().lerp(cCold, t);
                emissive = new THREE.Color(0xff5a1e);
                eInt = 0.5 * (1 - t) + 0.12;
            } else {
                color = cW.clone().lerp(cWhi, Math.min(1, hgt / 2.2));
            }
            const mat = new THREE.MeshStandardMaterial({
                color,
                emissive,
                emissiveIntensity: eInt,
                roughness: 0.88,
                metalness: 0.04,
                flatShading: true,
            });
            const m = new THREE.Mesh(geom, mat);
            m.scale.y = hgt;
            m.position.set(i - half, hgt / 2, j - half);
            group.add(m);
            meshes.push(m);
            if (isSpark) emberL.position.set(i - half, hgt + 1.4, j - half);
        }
    }
    group.add(emberL);

    return { scene, cam, group, geom, meshes };
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

    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (reduced) {
        built.group.rotation.y = 0.5;
        render();
    } else {
        const loop = () => {
            built.group.rotation.y += 0.0026;
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
                d = 6.4;
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
        built.geom.dispose();
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
