/**
 * String plumbing for ui-web components.
 *
 * The keyed-strings module arrives in WS-09; until then every user-facing
 * string is CONSUMED through a `strings` prop typed to the COPY.md keys the
 * component needs, with `{braces}` interpolation done here. Components never
 * hard-code copy.
 */

/** COPY.md keys the Board needs (a11y cell announcements). */
export type BoardStringKey =
  'a11y.cell.empty' | 'a11y.cell.break' | 'a11y.cell.dot' | 'a11y.cell.clue' | 'a11y.cell.spark';

/** COPY.md keys the BurnReplay needs. */
export type ReplayStringKey = 'a11y.replay.minute' | 'a11y.contained' | 'play.contained';

/** COPY.md keys the HUD pieces need. */
export type HudStringKey = 'play.breaks';

export type StringsFor<K extends string> = Readonly<Record<K, string>>;

/**
 * Replay control labels that have NO COPY.md key yet (proposed keys —
 * see tasks/WS-04/STATUS.md decisions; WS-09/lead to add them to the
 * catalog). Kept out of `strings` so the copy gap stays visible.
 */
export interface ReplayLabels {
  /** proposed `replay.watchAgain` */
  readonly watchAgain: string;
  /** proposed `replay.nextMinute` (reduced-motion stepper) */
  readonly nextMinute: string;
  /** proposed `replay.previousMinute` (reduced-motion stepper) */
  readonly previousMinute: string;
}

/** Fill `{name}` placeholders. Unknown placeholders are left verbatim. */
export function formatString(
  template: string,
  params: Readonly<Record<string, string | number>>,
): string {
  return template.replace(/\{(\w+)\}/g, (whole, name: string) => {
    const value = params[name];
    return value === undefined ? whole : String(value);
  });
}

/** Column letters as in the reference prototype (A1 = top-left). */
export function cellName(index: number, cols: number): string {
  const col = index % cols;
  const row = Math.floor(index / cols);
  return String.fromCharCode(65 + col) + String(row + 1);
}
