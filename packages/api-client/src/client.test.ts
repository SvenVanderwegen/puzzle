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

describe('createApiClient — Sanctum CSRF bootstrap (lead-sanctioned, WS-14)', () => {
  it('no-ops when the cookie already yields a token', async () => {
    const { calls, fetch } = fakeFetch(() => new Response(null, { status: 204 }));
    const client = createApiClient({ fetch, getCsrfToken: () => 'tok-1' });
    await client.post('/auth/logout');
    expect(calls.map((call) => call.url)).toEqual(['/api/v1/auth/logout']);
    expect(calls[0]?.headers.get('x-xsrf-token')).toBe('tok-1');
  });

  it('fires once before a mutating call when the cookie is missing, then sends the fresh token', async () => {
    let cookie: string | null = null;
    const { calls, fetch } = fakeFetch((call) => {
      if (call.url === '/sanctum/csrf-cookie') cookie = 'tok-fresh';
      return new Response(null, { status: 204 });
    });
    const client = createApiClient({ fetch, getCsrfToken: () => cookie });
    await client.post('/auth/logout');
    expect(calls.map((call) => call.url)).toEqual(['/sanctum/csrf-cookie', '/api/v1/auth/logout']);
    expect(calls[0]?.method).toBe('GET');
    expect(calls[0]?.credentials).toBe('include');
    expect(calls[1]?.headers.get('x-xsrf-token')).toBe('tok-fresh');
  });

  it('dedupes the in-flight bootstrap across concurrent mutating calls', async () => {
    let cookie: string | null = null;
    let releaseBootstrap = (): void => undefined;
    const gate = new Promise<void>((resolve) => {
      releaseBootstrap = resolve;
    });
    const calls: { url: string; token: string | null }[] = [];
    const fetch: typeof globalThis.fetch = async (input, init) => {
      const url =
        typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
      calls.push({ url, token: new Headers(init?.headers).get('x-xsrf-token') });
      if (url === '/sanctum/csrf-cookie') {
        await gate;
        cookie = 'tok-shared';
      }
      return new Response(null, { status: 204 });
    };
    const client = createApiClient({ fetch, getCsrfToken: () => cookie });

    const both = Promise.all([client.post('/auth/logout'), client.post('/auth/logout')]);
    // Let both requests reach (and share) the in-flight bootstrap, then open it.
    await new Promise((resolve) => setTimeout(resolve, 0));
    releaseBootstrap();
    await both;

    expect(calls.filter((call) => call.url === '/sanctum/csrf-cookie')).toHaveLength(1);
    const mutations = calls.filter((call) => call.url === '/api/v1/auth/logout');
    expect(mutations).toHaveLength(2);
    expect(mutations.map((call) => call.token)).toEqual(['tok-shared', 'tok-shared']);
  });

  it('never bootstraps for GET requests or when no token source is injected', async () => {
    const { calls, fetch } = fakeFetch(() => json(200, { ok: true, tomorrow_published: true }));
    await createApiClient({ fetch, getCsrfToken: () => null }).get('/health');
    expect(calls.map((call) => call.url)).toEqual(['/api/v1/health']);

    const bare = fakeFetch(() => new Response(null, { status: 204 }));
    await createApiClient({ fetch: bare.fetch }).post('/auth/logout');
    expect(bare.calls.map((call) => call.url)).toEqual(['/api/v1/auth/logout']);
  });

  it('swallows a failed bootstrap and lets the mutating request surface the error, re-arming for the next call', async () => {
    let bootstrapAttempts = 0;
    let cookie: string | null = null;
    const fetch: typeof globalThis.fetch = (input) => {
      const url =
        typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
      if (url === '/sanctum/csrf-cookie') {
        bootstrapAttempts += 1;
        if (bootstrapAttempts === 1) return Promise.reject(new TypeError('network down'));
        cookie = 'tok-2nd';
      }
      return Promise.resolve(new Response(null, { status: 204 }));
    };
    const client = createApiClient({ fetch, getCsrfToken: () => cookie });

    // First call: bootstrap fails silently; the request goes out tokenless.
    const first = await client.post('/auth/logout');
    expect(first.status).toBe(204);
    expect(bootstrapAttempts).toBe(1);

    // Cookie still missing → a later mutating call re-attempts and succeeds.
    await client.post('/auth/logout');
    expect(bootstrapAttempts).toBe(2);
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
