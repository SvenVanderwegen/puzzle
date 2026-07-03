/**
 * Default generator-worker factory — the ONLY module that references the
 * worker file (boundary.test.ts). Vite splits the worker into its own chunk
 * (native `new Worker(new URL(...), { type: 'module' })` support), so the
 * engine's generator never enters the main bundle's execution path.
 *
 * Returns null where Workers are unavailable (test DOMs, exotic embeds);
 * callers then stay in the loading state instead of blocking the main thread.
 */
import type { WorkerLike } from './generatorClient';

export function createGeneratorWorker(): WorkerLike | null {
  if (typeof Worker === 'undefined') return null;
  try {
    return new Worker(new URL('./generator.worker.ts', import.meta.url), { type: 'module' });
  } catch {
    return null;
  }
}
