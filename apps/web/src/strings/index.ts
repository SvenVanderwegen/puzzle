/**
 * The keyed-strings module (CLAUDE.md rule 7): every user-facing string in
 * apps/web goes through `t`. The catalog is GENERATED from contracts/COPY.md
 * (strings.gen.ts, CI-freshness-checked); EN only at launch — NL later is a
 * second generated catalog behind the same keys, not a refactor.
 */
import { formatIcu, type IcuParams } from './icu';
import { catalog, type CatalogKey } from './strings.gen';
import { proposedCatalog } from './proposed';

// The proposed-keys quarantine is empty (ADR-0017). When a future workstream
// adds a proposed key, widen this to `CatalogKey | ProposedKey` (import the
// type from './proposed') — the runtime merge below already handles it.
export type StringKey = CatalogKey;
export type { IcuParams };
export { catalog };

const merged: Readonly<Record<StringKey, string>> = { ...proposedCatalog, ...catalog };

/** Look up a COPY.md key and interpolate its `{braces}`/plural/select args. */
export function t(key: StringKey, params?: IcuParams): string {
  return formatIcu(merged[key], params);
}
