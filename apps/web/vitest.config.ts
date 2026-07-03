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
        // Test-only harness (mock contract client + render helper, WS-14).
        'src/testing/**',
        // DOM bootstrap only; exercised by the build, not unit-testable renderless.
        'src/main.tsx',
        // Same: the landing hydration entry (WS-15); logic lives in
        // src/landing/{boardJson,strip,HeroApp}, which are covered.
        'src/landing/hero.tsx',
      ],
      reporter: ['text-summary'],
      // Playbook §5 gate 3: apps/web floor is 70% lines.
      thresholds: { lines: 70 },
    },
  },
});
