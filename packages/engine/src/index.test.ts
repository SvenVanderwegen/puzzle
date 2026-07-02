import { describe, expect, it } from 'vitest';
import { ENGINE_PACKAGE } from './index';

describe('engine skeleton', () => {
  it('exports its package marker', () => {
    expect(ENGINE_PACKAGE).toBe('engine');
  });
});
