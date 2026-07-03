import { defineConfig } from 'vitest/config';

export default defineConfig({
  esbuild: { jsx: 'automatic' },
  test: {
    environment: 'happy-dom',
    setupFiles: ['src/test-setup.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/**/*.test.{ts,tsx}', 'src/testing/**', 'src/test-setup.ts'],
      reporter: ['text-summary'],
      // Playbook §5 gate 3: ui-web floor is 70% lines (brief tier).
      thresholds: { lines: 70 },
    },
  },
});
