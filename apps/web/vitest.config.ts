import { defineConfig } from 'vitest/config';

export default defineConfig({
  esbuild: { jsx: 'automatic' },
  test: {
    environment: 'happy-dom',
    setupFiles: ['src/test-setup.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,tsx}'],
      exclude: [
        'src/**/*.test.{ts,tsx}',
        'src/test-setup.ts',
        // DOM bootstrap only; exercised by the build, not unit-testable renderless.
        'src/main.tsx',
      ],
      reporter: ['text-summary'],
      // Playbook §5 gate 3: apps/web floor is 70% lines.
      thresholds: { lines: 70 },
    },
  },
});
