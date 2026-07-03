/**
 * Landing hydration bundle (WS-15) — a build SEPARATE from the SPA.
 *
 * Why its own config (decision recorded in tasks/WS-15/STATUS.md): the SPA
 * config carries vite-plugin-pwa + index.html templating that must not leak
 * into the marketing page, and ADR-0009 budgets the landing's deferred JS
 * independently (≤90KB gz total). Output is ONE self-contained ES module,
 * committed at api/resources/landing/hero.js and served by Laravel
 * (LandingController@heroModule) — Blade owns `/`, so there is no Vite
 * dev-server/manifest wiring on that page. Built + freshness-checked via
 * scripts/build-landing.mjs (`pnpm build:landing` / `pnpm budget:landing`).
 */
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
  // esbuild's automatic JSX transform suffices — no HMR/babel on this build.
  build: {
    target: 'es2022',
    outDir: fileURLToPath(new URL('../../api/resources/landing', import.meta.url)),
    // resources/landing also holds the committed fixture JSON + board.css.
    emptyOutDir: false,
    sourcemap: false,
    rollupOptions: {
      input: fileURLToPath(new URL('src/landing/hero.tsx', import.meta.url)),
      output: {
        format: 'es',
        entryFileNames: 'hero.js',
        // Cache-busting happens via a content-hash query string in Blade,
        // so the filename stays stable for the committed artifact.
        inlineDynamicImports: true,
      },
    },
  },
});
