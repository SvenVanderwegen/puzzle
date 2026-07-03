/**
 * SPA entry: mounts the router inside StrictMode. Service-worker
 * registration is injected at build time by vite-plugin-pwa
 * (injectRegister: 'inline'); nothing here talks to the network.
 */
import { RouterProvider } from '@tanstack/react-router';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { createAppRouter } from './router';
import { t } from './strings';

const container = document.getElementById('root');
if (container === null) {
  throw new Error('Missing #root container');
}
document.title = t('app.title');
createRoot(container).render(
  <StrictMode>
    <RouterProvider router={createAppRouter()} />
  </StrictMode>,
);
