import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      exclude: ['src/**/*.test.ts', 'src/testing/**'],
      reporter: ['text-summary'],
      // Playbook §5 gate 3: game-core floor is 90% lines.
      thresholds: { lines: 90 },
    },
  },
});
