// Dev harness for the fixture page (pnpm --filter @burnfront/ui-web dev).
// Not part of any production build; apps/web (WS-09) has its own Vite setup.
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  root: 'fixture',
  plugins: [react()],
  server: {
    fs: {
      // The fixture imports ../src and contracts/design-tokens.json.
      allow: ['..', '../../..'],
    },
  },
});
