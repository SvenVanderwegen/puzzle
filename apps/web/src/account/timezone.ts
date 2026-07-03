/**
 * IANA timezone helpers for the account surface (WS-14). The zone is only
 * used server-side to time streak-protection emails (openapi /me PATCH,
 * ADR-0002: day boundaries stay UTC regardless).
 */

/** The browser-detected IANA zone (Intl); UTC when detection is unavailable. */
export function detectedTimezone(): string {
  try {
    return new Intl.DateTimeFormat().resolvedOptions().timeZone;
  } catch {
    return 'UTC';
  }
}

/**
 * Zones for the settings select — the runtime's full list, plus UTC (the
 * server default; some runtimes omit it from supportedValuesOf) and the
 * current profile value even if this runtime does not list it.
 */
export function timezoneOptions(current: string): readonly string[] {
  let zones: readonly string[];
  try {
    zones = Intl.supportedValuesOf('timeZone');
  } catch {
    zones = [];
  }
  if (!zones.includes('UTC')) zones = ['UTC', ...zones];
  return zones.includes(current) ? zones : [current, ...zones];
}
