/**
 * The keyed-strings module (CLAUDE.md rule 7): every user-facing string in
 * apps/web goes through `t`. The catalog is GENERATED from contracts/COPY.md
 * (strings.gen.ts, CI-freshness-checked); EN only at launch — NL later is a
 * second generated catalog behind the same keys, not a refactor.
 */
import { formatIcu, type IcuParams } from './icu';
import { catalog, type CatalogKey } from './strings.gen';
import { proposedCatalog, type ProposedKey } from './proposed';

export type StringKey = CatalogKey | ProposedKey;
export type { IcuParams };
export { catalog };

const merged: Readonly<Record<StringKey, string>> = { ...proposedCatalog, ...catalog };

/** Look up a COPY.md key and interpolate its `{braces}`/plural/select args. */
export function t(key: StringKey, params?: IcuParams): string {
  return formatIcu(merged[key], params);
}
