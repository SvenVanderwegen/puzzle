import { describe, expect, it } from 'vitest';
import { API_CLIENT_PACKAGE } from './index';

describe('api-client skeleton', () => {
  it('exports its package marker', () => {
    expect(API_CLIENT_PACKAGE).toBe('api-client');
  });
});
