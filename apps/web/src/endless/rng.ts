/**
 * Seeded randomness for Endless generation. The engine takes an injected Rng
 * (CLAUDE.md rule 8); the app boundary seeds it from WebCrypto — never
 * Math.random. sfc32 keeps the worker deterministic per seed, so the same
 * four words always reproduce the same board (testable end to end).
 */
import type { Rng } from '@burnfront/engine';

export const SEED_WORDS = 4;

/** sfc32 over four injected 32-bit words. Deterministic given the seed. */
export function seededRng(seed: readonly number[]): Rng {
  let a = (seed[0] ?? 0) >>> 0;
  let b = (seed[1] ?? 0) >>> 0;
  let c = (seed[2] ?? 0) >>> 0;
  let d = (seed[3] ?? 0) >>> 0;
  // Escape the degenerate all-zero state.
  if ((a | b | c | d) === 0) d = 0x9e3779b9;
  return () => {
    a >>>= 0;
    b >>>= 0;
    c >>>= 0;
    d >>>= 0;
    let t = (a + b) | 0;
    a = b ^ (b >>> 9);
    b = (c + (c << 3)) | 0;
    c = (c << 21) | (c >>> 11);
    d = (d + 1) | 0;
    t = (t + d) | 0;
    c = (c + t) | 0;
    return (t >>> 0) / 4294967296;
  };
}

/** Fresh generation seed from WebCrypto (the app boundary's entropy source). */
export function cryptoSeed(): readonly number[] {
  const words = new Uint32Array(SEED_WORDS);
  crypto.getRandomValues(words);
  return [...words];
}

/** Buffered WebCrypto-backed Rng (Idempotency-Key UUID v7 randomness). */
export function cryptoRng(): Rng {
  const buffer = new Uint32Array(64);
  let index = buffer.length;
  return () => {
    if (index >= buffer.length) {
      crypto.getRandomValues(buffer);
      index = 0;
    }
    const word = buffer[index] ?? 0;
    index += 1;
    return word / 4294967296;
  };
}
