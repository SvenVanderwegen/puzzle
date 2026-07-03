import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      // types.gen.ts is generated types only (no runtime) — excluded per brief.
      exclude: ['src/**/*.test.ts', 'src/types.gen.ts'],
      reporter: ['text-summary'],
      // Playbook §5 gate 3: floor for non-engine packages is 70% lines.
      thresholds: { lines: 70 },
    },
  },
});
