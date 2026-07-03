import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterEach } from 'vitest';

// waitFor's 1s real-timer default flakes under CPU contention (2-core CI
// runners, parallel gate legs) — reproduced twice in cold fully-parallel
// runs (Timeout.checkRealTimersCallback in wait-for.js). Headroom, not a
// behavior change: assertions still resolve as soon as they pass.
configure({ asyncUtilTimeout: 5000 });

afterEach(() => {
  cleanup();
});
