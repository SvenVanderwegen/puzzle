/**
 * ICU-lite interpolation for the COPY.md catalog: `{name}` substitution plus
 * the two documented message-format cases — plural (daily.solvedBy) and
 * select+plural (share.line2). Exact matches (`=0`), `one`, `other`, and `#`
 * (the number inside a plural branch) are supported; nothing more is,
 * deliberately — COPY.md is the contract, not full ICU.
 * Unknown or missing placeholders stay verbatim (same policy as ui-web).
 */
export type IcuParams = Readonly<Record<string, string | number>>;

/** Index of the `}` matching the `{` at `open`, or -1 if unbalanced. */
function matchingBrace(template: string, open: number): number {
  let depth = 0;
  for (let i = open; i < template.length; i += 1) {
    const ch = template[i];
    if (ch === '{') depth += 1;
    if (ch === '}') {
      depth -= 1;
      if (depth === 0) return i;
    }
  }
  return -1;
}

interface Branch {
  readonly selector: string;
  readonly body: string;
}

/** Parses `sel {body} sel {body} …` inside a plural/select argument. */
function parseBranches(src: string): readonly Branch[] | null {
  const branches: Branch[] = [];
  let i = 0;
  while (i < src.length) {
    while (i < src.length && /\s/.test(src[i] as string)) i += 1;
    if (i >= src.length) break;
    const selectorMatch = /^[=\w]+/.exec(src.slice(i));
    if (selectorMatch === null) return null;
    const selector = selectorMatch[0];
    i += selector.length;
    while (i < src.length && /\s/.test(src[i] as string)) i += 1;
    if (src[i] !== '{') return null;
    const close = matchingBrace(src, i);
    if (close === -1) return null;
    branches.push({ selector, body: src.slice(i + 1, close) });
    i = close + 1;
  }
  return branches;
}

function pluralBranch(branches: readonly Branch[], n: number): Branch | undefined {
  return (
    branches.find((b) => b.selector === `=${String(n)}`) ??
    (n === 1 ? branches.find((b) => b.selector === 'one') : undefined) ??
    branches.find((b) => b.selector === 'other')
  );
}

function resolveArgument(inner: string, params: IcuParams, verbatim: string): string {
  const complex = /^(\w+)\s*,\s*(plural|select)\s*,\s*([\s\S]*)$/.exec(inner);
  if (complex !== null) {
    const [, name, kind, rest] = complex as unknown as [
      string,
      string,
      'plural' | 'select',
      string,
    ];
    const value = params[name];
    if (value === undefined) return verbatim;
    const branches = parseBranches(rest);
    if (branches === null) return verbatim;
    if (kind === 'plural') {
      const n = Number(value);
      const branch = pluralBranch(branches, n);
      if (branch === undefined) return verbatim;
      return formatIcu(branch.body.replaceAll('#', String(n)), params);
    }
    const branch =
      branches.find((b) => b.selector === String(value)) ??
      branches.find((b) => b.selector === 'other');
    if (branch === undefined) return verbatim;
    return formatIcu(branch.body, params);
  }
  if (/^\w+$/.test(inner)) {
    const value = params[inner];
    return value === undefined ? verbatim : String(value);
  }
  return verbatim;
}

/** Fills an ICU-lite template. Unresolvable arguments are left verbatim. */
export function formatIcu(template: string, params: IcuParams = {}): string {
  let out = '';
  let i = 0;
  while (i < template.length) {
    const ch = template[i] as string;
    if (ch === '{') {
      const close = matchingBrace(template, i);
      if (close !== -1) {
        const verbatim = template.slice(i, close + 1);
        out += resolveArgument(template.slice(i + 1, close), params, verbatim);
        i = close + 1;
        continue;
      }
    }
    out += ch;
    i += 1;
  }
  return out;
}
