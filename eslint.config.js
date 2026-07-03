// Flat ESLint config — TS strict across all workspaces.
import js from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  {
    ignores: ['**/node_modules/**', '**/dist/**', '**/coverage/**', 'reference/**', 'contracts/**'],
  },
  js.configs.recommended,
  ...tseslint.configs.strictTypeChecked,
  {
    languageOptions: {
      parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname },
    },
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      'no-console': 'error',
    },
  },
  {
    files: ['**/*.test.ts'],
    rules: { 'no-console': 'off' },
  },
  {
    // Determinism law (CLAUDE.md rule 8): clock and RNG are injected.
    files: ['packages/engine/src/**', 'packages/game-core/src/**'],
    ignores: ['**/*.test.ts'],
    rules: {
      'no-restricted-properties': [
        'error',
        { object: 'Date', property: 'now', message: 'Inject the clock (CLAUDE.md rule 8).' },
        { object: 'Math', property: 'random', message: 'Inject the RNG (CLAUDE.md rule 8).' },
        { object: 'performance', property: 'now', message: 'Inject the clock (CLAUDE.md rule 8).' },
      ],
    },
  },
);
