/**
 * Client wrapper tests: runtime behavior against an injected fetch, plus the
 * type-lock — wrong paths/verbs/bodies are compile errors (@ts-expect-error
 * lines fail the typecheck gate if the lock ever loosens).
 */
import { describe, expect, expectTypeOf, it } from 'vitest';
import type { components } from './types.gen';
import { ApiError, createApiClient } from './client';

interface Call {
  url: string;
  method: string | undefined;
  headers: Headers;
  body: string | undefined;
  credentials: string | undefined;
}

function fakeFetch(respond: (call: Call) => Response): {
  calls: Call[];
  fetch: typeof globalThis.fetch;
} {
  const calls: Call[] = [];
  const impl = (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const call: Call = {
      url: typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url,
      method: init?.method,
      headers: new Headers(init?.headers),
      body: typeof init?.body === 'string' ? init.body : undefined,
      credentials: init?.credentials,
    };
    calls.push(call);
    return Promise.resolve(respond(call));
  };
  return { calls, fetch: impl };
}

const json = (status: number, body: unknown): Response =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });

const daily: components['schemas']['Daily'] = {
  date: '2026-07-03',
  incident_number: 12,
  puzzle_id: 'p-12',
  grade_tier: 'crew',
  content_url: 'https://burnfront.com/content/2026-07-03.json',
  amnesty: false,
  stats: { solved_count: 412 },
};

describe('createApiClient — request construction', () => {
  it('substitutes path params and prefixes the default base url', async () => {
    const { calls, fetch } = fakeFetch(() => json(200, daily));
    const client = createApiClient({ fetch });
    const result = await client.get('/daily/{date}', { path: { date: '2026-07-03' } });
    expect(calls[0]?.url).toBe('/api/v1/daily/2026-07-03');
    expect(calls[0]?.method).toBe('GET');
    expect(calls[0]?.credentials).toBe('include');
    expect(result.status).toBe(200);
    if (result.status === 200) {
      expect(result.data.incident_number).toBe(12);
      expectTypeOf(result.data).toEqualTypeOf<components['schemas']['Daily']>();
      expectTypeOf(result.ok).toEqualTypeOf<true>();
    }
  });

  it('URI-encodes path params', async () => {
    const { calls, fetch } = fakeFetch(() => json(200, daily));
    await createApiClient({ fetch }).get('/daily/{date}', { path: { date: 'a/b c' } });
    expect(calls[0]?.url).toBe('/api/v1/daily/a%2Fb%20c');
  });

  it('serializes query params against a custom base url', async () => {
    const { calls, fetch } = fakeFetch(() => json(200, { items: [] }));
    const client = createApiClient({ fetch, baseUrl: 'https://staging.burnfront.com/api/v1' });
    await client.get('/me/solves', { query: { limit: 10 } });
    expect(calls[0]?.url).toBe('https://staging.burnfront.com/api/v1/me/solves?limit=10');
  });

  it('sends JSON bodies with content-type and the accept header', async () => {
    const { calls, fetch } = fakeFetch(() => new Response(null, { status: 202 }));
    const client = createApiClient({ fetch });
    const result = await client.post('/auth/magic-link', { body: { email: 'sven@vbc.be' } });
    expect(calls[0]?.headers.get('content-type')).toBe('application/json');
    expect(calls[0]?.headers.get('accept')).toBe('application/json');
    expect(calls[0]?.body).toBe('{"email":"sven@vbc.be"}');
    expect(result.status).toBe(202);
    expect(result.data).toBeUndefined();
  });

  it('sends declared header params (Idempotency-Key on submitSolve)', async () => {
    const { calls, fetch } = fakeFetch(() =>
      json(201, { solve_id: 's1', valid: true, suspect: false }),
    );
    const client = createApiClient({ fetch });
    const result = await client.post('/solves', {
      header: { 'Idempotency-Key': '0190815d-aaaa-7000-8000-000000000000' },
      body: {
        mode: 'daily',
        puzzle_id: 'p-12',
        shaded: '0110',
        client_ms: 61000,
        started_at: '2026-07-03T00:01:00Z',
        hints: { s1: 0, s2: 0, s3: 0 },
        undo_count: 2,
      },
    });
    expect(calls[0]?.headers.get('idempotency-key')).toBe('0190815d-aaaa-7000-8000-000000000000');
    if (result.status === 201) {
      expectTypeOf(result.data).toEqualTypeOf<components['schemas']['SolveResult']>();
    }
  });

  it('adds X-XSRF-TOKEN on mutating requests only', async () => {
    const { calls, fetch } = fakeFetch((call) =>
      call.method === 'GET' ? json(200, daily) : new Response(null, { status: 204 }),
    );
    const client = createApiClient({ fetch, getCsrfToken: () => 'tok-123' });
    await client.post('/auth/logout');
    await client.get('/daily/{date}', { path: { date: '2026-07-03' } });
    expect(calls[0]?.headers.get('x-xsrf-token')).toBe('tok-123');
    expect(calls[1]?.headers.get('x-xsrf-token')).toBeNull();
  });
});

describe('createApiClient — responses', () => {
  it('returns documented non-2xx statuses as typed variants', async () => {
    const { fetch } = fakeFetch(() => new Response(null, { status: 404 }));
    const result = await createApiClient({ fetch }).get('/daily/{date}', {
      path: { date: '2027-01-01' },
    });
    expect(result.status).toBe(404);
    expect(result.ok).toBe(false);
    expect(result.data).toBeUndefined();
  });

  it('parses the error envelope for documented error statuses', async () => {
    const { fetch } = fakeFetch(() =>
      json(429, { error: { code: 'rate_limited', message: 'Too many requests.' } }),
    );
    const result = await createApiClient({ fetch }).post('/auth/magic-link', {
      body: { email: 'sven@vbc.be' },
    });
    expect(result.status).toBe(429);
    if (result.status === 429) {
      expect(result.data.error.code).toBe('rate_limited');
      expectTypeOf(result.data).toEqualTypeOf<components['schemas']['Error']>();
    }
  });

  it('returns undefined data for non-JSON bodies', async () => {
    const { fetch } = fakeFetch(() => new Response('<html></html>', { status: 200 }));
    const result = await createApiClient({ fetch }).get('/health');
    expect(result.data).toBeUndefined();
  });

  it('throws ApiError for statuses outside the contract (>= 500)', async () => {
    const { fetch } = fakeFetch(() => new Response('boom', { status: 502 }));
    const client = createApiClient({ fetch });
    await expect(client.get('/health')).rejects.toThrowError(ApiError);
    await expect(client.get('/health')).rejects.toMatchObject({ status: 502, bodyText: 'boom' });
  });

  it('rejects a template path whose params were not provided at runtime', async () => {
    const { fetch } = fakeFetch(() => json(200, daily));
    const client = createApiClient({ fetch });
    // @ts-expect-error — path params are required by the type lock too
    await expect(client.get('/daily/{date}')).rejects.toThrow('Missing path parameter');
  });
});

describe('type lock — the contract is the compiler', () => {
  it('rejects unknown paths, wrong verbs and malformed bodies at compile time', () => {
    const client = createApiClient({ fetch: fakeFetch(() => json(200, {})).fetch });
    // @ts-expect-error — path not in contracts/openapi.yaml
    void client.get('/daily');
    // @ts-expect-error — /me has no POST
    void client.post('/me');
    // @ts-expect-error — magic-link body requires `email`
    void client.post('/auth/magic-link', { body: {} });
    // @ts-expect-error — /solves requires the Idempotency-Key header
    void client.post('/solves', {
      body: {
        mode: 'daily',
        shaded: '0',
        client_ms: 1,
        started_at: 't',
        hints: { s1: 0, s2: 0, s3: 0 },
        undo_count: 0,
      },
    });
    expect(true).toBe(true);
  });
});
