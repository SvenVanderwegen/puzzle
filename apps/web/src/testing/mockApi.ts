/**
 * ApiClient mock for feature tests: canned responses per `METHOD path`,
 * sequential responses (arrays) for pagination flows, every call recorded.
 * An unrouted call throws — tests assert the exact network surface.
 */
import type { ApiClient } from '@burnfront/api-client';

export interface RecordedCall {
  readonly method: string;
  readonly path: string;
  readonly body?: unknown;
  readonly query?: Readonly<Record<string, unknown>>;
}

export interface CannedResponse {
  readonly status: number;
  readonly data?: unknown;
}

export type Responder =
  CannedResponse | readonly CannedResponse[] | ((call: RecordedCall) => CannedResponse);

export interface MockApi {
  readonly api: ApiClient;
  readonly calls: RecordedCall[];
  /** Calls filtered to `METHOD path` (e.g. "POST /auth/magic-link"). */
  readonly callsTo: (key: string) => RecordedCall[];
}

interface RawOptions {
  readonly body?: unknown;
  readonly query?: Readonly<Record<string, unknown>>;
}

export function mockApi(routes: Readonly<Record<string, Responder>>): MockApi {
  const calls: RecordedCall[] = [];
  const sequenceIndex = new Map<string, number>();

  function respond(method: string, path: string, options: RawOptions): Promise<unknown> {
    const call: RecordedCall = { method: method.toUpperCase(), path, ...options };
    calls.push(call);
    const key = `${call.method} ${path}`;
    const responder = routes[key];
    if (responder === undefined) throw new Error(`mockApi: no route for ${key}`);
    let canned: CannedResponse;
    if (typeof responder === 'function') {
      canned = responder(call);
    } else if (Array.isArray(responder)) {
      const sequence = responder as readonly CannedResponse[];
      const index = sequenceIndex.get(key) ?? 0;
      sequenceIndex.set(key, index + 1);
      const picked = sequence[Math.min(index, sequence.length - 1)];
      if (picked === undefined) throw new Error(`mockApi: empty sequence for ${key}`);
      canned = picked;
    } else {
      canned = responder as CannedResponse;
    }
    return Promise.resolve({
      status: canned.status,
      ok: canned.status >= 200 && canned.status < 300,
      data: canned.data,
      response: new Response(),
    });
  }

  // The generic client surface narrows to the contract; the mock mirrors the
  // real client's single typed/untyped seam (client.ts) with one cast.
  const api = {
    get: (path: string, options?: RawOptions) => respond('get', path, options ?? {}),
    post: (path: string, options?: RawOptions) => respond('post', path, options ?? {}),
    patch: (path: string, options?: RawOptions) => respond('patch', path, options ?? {}),
    delete: (path: string, options?: RawOptions) => respond('delete', path, options ?? {}),
  } as unknown as ApiClient;

  return {
    api,
    calls,
    callsTo: (key) => calls.filter((call) => `${call.method} ${call.path}` === key),
  };
}
