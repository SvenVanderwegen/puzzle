import { describe, expect, it } from 'vitest';
import { UI_WEB_PACKAGE } from './index';

describe('ui-web skeleton', () => {
  it('exports its package marker', () => {
    expect(UI_WEB_PACKAGE).toBe('ui-web');
  });
});
