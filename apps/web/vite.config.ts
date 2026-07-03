/**
 * apps/web build (ADR-0009): Vite + React SPA, vite-plugin-pwa precaching the
 * shell. Workbox runtime is inlined (zero third-party requests — product §2);
 * html placeholders are filled from the contracts at build time.
 */
import react from '@vitejs/plugin-react';
import { defineConfig, type Plugin } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';
import designTokens from '../../contracts/design-tokens.json';
import { catalog } from './src/strings/strings.gen';

/** Fills index.html placeholders from COPY.md / design-tokens.json. */
function contractHtml(): Plugin {
  return {
    name: 'burnfront-contract-html',
    transformIndexHtml: (html) =>
      html
        .replaceAll('%APP_TITLE%', catalog['app.title'])
        .replaceAll('%APP_TAGLINE%', catalog['app.tagline'])
        .replaceAll('%THEME_COLOR%', designTokens.color.soot.value),
  };
}

export default defineConfig({
  plugins: [
    react(),
    contractHtml(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'inline',
      manifest: {
        name: catalog['app.title'],
        short_name: catalog['app.title'],
        description: catalog['app.tagline'],
        start_url: '/',
        display: 'standalone',
        background_color: designTokens.color.soot.value,
        theme_color: designTokens.color.soot.value,
        // Branded icons arrive with the launch pass (WS-15/WS-17).
        icons: [],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,webmanifest}'],
        // Self-contained service worker: no workbox CDN, no external requests.
        inlineWorkboxRuntime: true,
        navigateFallback: 'index.html',
        cleanupOutdatedCaches: true,
      },
    }),
  ],
  build: {
    target: 'es2022',
  },
});
