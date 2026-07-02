import { describe, expect, it } from 'vitest';
import { GAME_CORE_PACKAGE } from './index';

describe('game-core skeleton', () => {
  it('exports its package marker', () => {
    expect(GAME_CORE_PACKAGE).toBe('game-core');
  });
});
