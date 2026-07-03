/**
 * Thin fetch wrapper TYPE-LOCKED to the generated `paths` interface
 * (types.gen.ts, generated from contracts/openapi.yaml — playbook §5 gate 5).
 *
 * Every method's path, verb, path/query/header params, request body and
 * response union derive from `paths`; a path or verb the contract does not
 * declare is a compile error, as is a missing required param or body.
 * Zero dependencies, native fetch (DEPENDENCIES.md: axios/ky rejected).
 *
 * Responses come back as a status-discriminated union of the DOCUMENTED
 * statuses. Server conformance to the contract is enforced server-side
 * (Spectator); a status ≥ 500 is outside every operation's contract and
 * throws `ApiError`.
 */
import type { paths } from './types.gen';

export type HttpMethod = 'get' | 'post' | 'patch' | 'delete' | 'put';

/** Paths that declare method M — the only strings the methods accept. */
export type PathsWithMethod<M extends HttpMethod> = {
  [P in keyof paths]: paths[P] extends Record<M, unknown> ? P : never;
}[keyof paths];

/** The generated operation object for path P + method M. */
export type OperationFor<P extends keyof paths, M extends HttpMethod> =
  paths[P] extends Record<M, infer Op> ? Op : never;

type ResponsesOf<Op> = Op extends { responses: infer R } ? R : never;
type JsonOf<R> = R extends { content: { 'application/json': infer D } } ? D : undefined;
type SuccessStatus = 200 | 201 | 202 | 204;

/** Status-discriminated union of an operation's documented responses. */
export type ResultOf<Op> = {
  [S in keyof ResponsesOf<Op> & number]: {
    status: S;
    ok: S extends SuccessStatus ? true : false;
    data: JsonOf<ResponsesOf<Op>[S]>;
    response: Response;
  };
}[keyof ResponsesOf<Op> & number];

type PathParamsOf<Op> = Op extends { parameters: { path: infer T } } ? T : never;
type QueryParamsOf<Op> = Op extends { parameters: { query?: infer T } }
  ? Exclude<T, undefined>
  : never;
type HeaderParamsOf<Op> = Op extends { parameters: { header: infer T } } ? T : never;
type BodyOf<Op> = Op extends { requestBody: { content: { 'application/json': infer B } } }
  ? B
  : never;

type PathPart<Op> = [PathParamsOf<Op>] extends [never]
  ? { path?: never }
  : { path: PathParamsOf<Op> };
type QueryPart<Op> = [QueryParamsOf<Op>] extends [never]
  ? { query?: never }
  : { query?: QueryParamsOf<Op> };
type HeaderPart<Op> = [HeaderParamsOf<Op>] extends [never]
  ? { header?: never }
  : { header: HeaderParamsOf<Op> };
type BodyPart<Op> = [BodyOf<Op>] extends [never] ? { body?: never } : { body: BodyOf<Op> };

/** Per-operation request options: only what the contract declares, required when it requires it. */
export type RequestOptions<Op> = PathPart<Op> &
  QueryPart<Op> &
  HeaderPart<Op> &
  BodyPart<Op> & { signal?: AbortSignal };

type RequiredKeys<T> = { [K in keyof T]-?: undefined extends T[K] ? never : K }[keyof T];
type OptionsArg<Op> = [RequiredKeys<RequestOptions<Op>>] extends [never]
  ? [options?: RequestOptions<Op>]
  : [options: RequestOptions<Op>];

/** A response with a status the contract does not document for the operation (≥ 500). */
export class ApiError extends Error {
  readonly status: number;
  readonly bodyText: string;

  constructor(status: number, bodyText: string) {
    super(`API responded outside the contract: HTTP ${String(status)}`);
    this.name = 'ApiError';
    this.status = status;
    this.bodyText = bodyText;
  }
}

export interface ApiClientOptions {
  /** Same-origin API base (openapi.yaml servers); default '/api/v1'. */
  readonly baseUrl?: string;
  /** Injected fetch (tests, node); default globalThis.fetch. */
  readonly fetch?: typeof globalThis.fetch;
  /**
   * Sanctum CSRF token source (openapi.yaml info.description): the value for
   * X-XSRF-TOKEN on mutating requests. Injected so this package stays DOM-free.
   */
  readonly getCsrfToken?: () => string | null;
}

interface RawOptions {
  readonly path?: Record<string, string | number>;
  readonly query?: Record<string, string | number | undefined>;
  readonly header?: Record<string, string>;
  readonly body?: unknown;
  readonly signal?: AbortSignal;
}

function buildUrl(baseUrl: string, path: string, options: RawOptions): string {
  const filled = path.replace(/\{(\w+)\}/g, (_whole, name: string) => {
    const value = options.path?.[name];
    if (value === undefined) {
      throw new Error(`Missing path parameter {${name}} for ${path}`);
    }
    return encodeURIComponent(String(value));
  });
  const search = new URLSearchParams();
  for (const [name, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined) search.set(name, String(value));
  }
  const qs = search.toString();
  return baseUrl + filled + (qs === '' ? '' : `?${qs}`);
}

async function parseData(response: Response): Promise<unknown> {
  if (response.status === 204) return undefined;
  const contentType = response.headers.get('content-type') ?? '';
  if (!contentType.includes('json')) return undefined;
  const text = await response.text();
  if (text === '') return undefined;
  return JSON.parse(text) as unknown;
}

export interface ApiClient {
  get<P extends PathsWithMethod<'get'>>(
    path: P,
    ...args: OptionsArg<OperationFor<P, 'get'>>
  ): Promise<ResultOf<OperationFor<P, 'get'>>>;
  post<P extends PathsWithMethod<'post'>>(
    path: P,
    ...args: OptionsArg<OperationFor<P, 'post'>>
  ): Promise<ResultOf<OperationFor<P, 'post'>>>;
  patch<P extends PathsWithMethod<'patch'>>(
    path: P,
    ...args: OptionsArg<OperationFor<P, 'patch'>>
  ): Promise<ResultOf<OperationFor<P, 'patch'>>>;
  delete<P extends PathsWithMethod<'delete'>>(
    path: P,
    ...args: OptionsArg<OperationFor<P, 'delete'>>
  ): Promise<ResultOf<OperationFor<P, 'delete'>>>;
}

const MUTATING = new Set<HttpMethod>(['post', 'patch', 'delete', 'put']);

export function createApiClient(clientOptions: ApiClientOptions = {}): ApiClient {
  const baseUrl = clientOptions.baseUrl ?? '/api/v1';
  const doFetch = clientOptions.fetch ?? globalThis.fetch;

  async function request(method: HttpMethod, path: string, options: RawOptions): Promise<unknown> {
    const headers = new Headers({ accept: 'application/json' });
    for (const [name, value] of Object.entries(options.header ?? {})) {
      headers.set(name, value);
    }
    const init: RequestInit = {
      method: method.toUpperCase(),
      headers,
      credentials: 'include',
    };
    if (options.body !== undefined) {
      headers.set('content-type', 'application/json');
      init.body = JSON.stringify(options.body);
    }
    if (MUTATING.has(method)) {
      const token = clientOptions.getCsrfToken?.() ?? null;
      if (token !== null) headers.set('x-xsrf-token', token);
    }
    if (options.signal !== undefined) init.signal = options.signal;

    const response = await doFetch(buildUrl(baseUrl, path, options), init);
    if (response.status >= 500) {
      throw new ApiError(response.status, await response.text());
    }
    return {
      status: response.status,
      ok: response.ok,
      data: await parseData(response),
      response,
    };
  }

  // The public surface narrows `request` to the generated contract types;
  // the casts below are the single typed/untyped seam in this package.
  return {
    get: (path, ...args) => request('get', path, (args[0] ?? {}) as RawOptions),
    post: (path, ...args) => request('post', path, (args[0] ?? {}) as RawOptions),
    patch: (path, ...args) => request('patch', path, (args[0] ?? {}) as RawOptions),
    delete: (path, ...args) => request('delete', path, (args[0] ?? {}) as RawOptions),
  } as ApiClient;
}
