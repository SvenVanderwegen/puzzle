import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { FixtureApp } from '../src/fixture/FixtureApp';

const root = document.getElementById('root');
if (root === null) throw new Error('fixture: #root missing');
createRoot(root).render(
  <StrictMode>
    <FixtureApp />
  </StrictMode>,
);
