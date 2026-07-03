/**
 * Landing hydration entry (WS-15) — the ONE deferred module the Blade
 * landing page loads (ADR-0009 budget: ≤90KB gz total, measured by
 * scripts/build-landing.mjs and api's LandingBudgetTest).
 *
 * Server HTML is complete without it (static hero board, final-frame replay
 * strip). This module upgrades in place: mounts the React <HeroApp> over the
 * static hero (same classes/geometry — no layout shift) and starts the
 * replay-strip loop. Built standalone by vite.landing.config.ts into
 * api/resources/landing/hero.js; DOM bootstrap only, so excluded from
 * coverage like main.tsx (logic lives in boardJson/strip/HeroApp, tested).
 */
import { createRoot } from 'react-dom/client';
import { parseBoardSpec } from './boardJson';
import { HeroApp } from './HeroApp';
import { initStrip } from './strip';

function hydrateHero(): void {
  const data = document.getElementById('bf-hero-board');
  const mount = document.querySelector<HTMLElement>('[data-bf-hero-mount]');
  if (data === null || mount === null) return;
  const board = parseBoardSpec(data.textContent ?? '');
  if (board === null) return; // static board stays up
  createRoot(mount).render(<HeroApp board={board} />);
}

function hydrateStrip(): void {
  const root = document.querySelector<HTMLElement>('[data-bf-strip]');
  if (root !== null) initStrip(root);
}

hydrateHero();
hydrateStrip();
