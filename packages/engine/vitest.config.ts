import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    testTimeout: 120_000,
    // Perf assertions need the CPU to themselves; run files sequentially.
    fileParallelism: false,
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      exclude: ['src/**/*.test.ts', 'src/env.d.ts'],
      reporter: ['text-summary'],
      thresholds: { lines: 95 },
    },
  },
});
