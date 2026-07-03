/**
 * Browser implementations of game-core's injected solve-record ports:
 * gzip via CompressionStream and SHA-256 via WebCrypto (both native — no
 * dependencies, per contracts/DEPENDENCIES.md). Tests may swap these for
 * the identity/fake versions; production uses these.
 */
import type { Compressor, Hasher } from '@burnfront/game-core';

export const gzipCompressor: Compressor = {
  compress: async (data) => {
    const stream = new Blob([data as BlobPart]).stream().pipeThrough(new CompressionStream('gzip'));
    const buffer = await new Response(stream).arrayBuffer();
    return new Uint8Array(buffer);
  },
};

export const webHasher: Hasher = {
  sha256Hex: async (data) => {
    const digest = await crypto.subtle.digest('SHA-256', data);
    return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  },
};
