/** Row-major bit-string codec ('1' = firebreak), as used by the vectors. */
import type { Shading } from './types';

export function shadingToBits(shading: Shading): string {
  let bits = '';
  for (const b of shading) bits += b ? '1' : '0';
  return bits;
}

export function bitsToShading(bits: string): Shading {
  const shading: boolean[] = [];
  for (const ch of bits) {
    if (ch !== '0' && ch !== '1') {
      throw new Error(`bitsToShading: invalid character ${JSON.stringify(ch)}`);
    }
    shading.push(ch === '1');
  }
  return shading;
}
