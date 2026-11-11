/**
 * Deterministic randomness for seeded fires (the daily fire and the
 * campaign). Same xorshift32 as app/Support/Burnfront/SeededRandom.php, so a
 * seed means the same thing on both sides.
 */

export type Random = () => number;

/** xorshift32, returns floats in [0, 1). */
export function seededRandom(seed: number): Random {
  let state = seed & 0x7fffffff;
  if (state === 0) state = 1;
  return () => {
    let x = state >>> 0;
    x = (x ^ (x << 13)) >>> 0;
    x = (x ^ (x >>> 17)) >>> 0;
    x = (x ^ (x << 5)) >>> 0;
    state = x;
    return (state & 0x7fffffff) / 0x80000000;
  };
}

let crcTable: Uint32Array | null = null;

/** CRC-32 of a string's UTF-8 bytes, same value as PHP's crc32(). */
export function crc32(text: string): number {
  if (!crcTable) {
    crcTable = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
      let c = n;
      for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
      crcTable[n] = c >>> 0;
    }
  }
  const bytes = utf8(text);
  let crc = 0xffffffff;
  for (let i = 0; i < bytes.length; i++) crc = crcTable[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

function utf8(text: string): number[] {
  const out: number[] = [];
  for (const ch of text) {
    const cp = ch.codePointAt(0) ?? 0;
    if (cp < 0x80) out.push(cp);
    else if (cp < 0x800) out.push(0xc0 | (cp >> 6), 0x80 | (cp & 63));
    else if (cp < 0x10000) out.push(0xe0 | (cp >> 12), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
    else {
      out.push(0xf0 | (cp >> 18), 0x80 | ((cp >> 12) & 63), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
    }
  }
  return out;
}

export function seedFor(namespace: string, key: string): number {
  return crc32(`${namespace}|${key}`);
}
