/**
 * @burnfront/api-client — the ONLY way frontend code talks to the API
 * (CLAUDE.md rule 2). types.gen.ts is generated from contracts/openapi.yaml;
 * client.ts is a thin native-fetch wrapper type-locked to it.
 */
export { ApiError, createApiClient } from './client';
export type {
  ApiClient,
  ApiClientOptions,
  HttpMethod,
  OperationFor,
  PathsWithMethod,
  RequestOptions,
  ResultOf,
} from './client';
export type { components, operations, paths } from './types.gen';
