import { describe, expect, it } from 'vitest';
import { WEB_APP } from './index';

describe('web skeleton', () => {
  it('exports its package marker', () => {
    expect(WEB_APP).toBe('web');
  });
});
