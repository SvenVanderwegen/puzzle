// apps/web lint = the repo config + the api-client guard (CLAUDE.md rule 2):
// no hand-written fetch anywhere in the SPA — network access goes through
// the generated, type-locked @burnfront/api-client only.
import rootConfig from '../../eslint.config.js';

const FETCH_MESSAGE =
  'Hand-written fetch is banned in apps/web — use @burnfront/api-client (CLAUDE.md rule 2).';

export default [
  ...rootConfig,
  {
    files: ['src/**/*.ts', 'src/**/*.tsx'],
    rules: {
      'no-restricted-globals': ['error', { name: 'fetch', message: FETCH_MESSAGE }],
      'no-restricted-properties': [
        'error',
        { object: 'globalThis', property: 'fetch', message: FETCH_MESSAGE },
        { object: 'window', property: 'fetch', message: FETCH_MESSAGE },
        { object: 'self', property: 'fetch', message: FETCH_MESSAGE },
      ],
    },
  },
];
