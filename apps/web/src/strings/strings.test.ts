/**
 * Catalog integrity: the generated map carries every COPY.md section
 * (spot-checked per section + total count) and stays parse-free at runtime.
 * Freshness against contracts/COPY.md is CI-verified by `strings:check`.
 */
import { describe, expect, it } from 'vitest';
import { catalog } from './strings.gen';
import { proposedCatalog } from './proposed';

describe('strings.gen catalog', () => {
  it('holds all 96 COPY.md keys', () => {
    expect(Object.keys(catalog)).toHaveLength(96);
  });

  it('covers every COPY.md section', () => {
    expect(catalog['app.tagline']).toBe('Every board is provably fair.');
    expect(catalog['rules.note.aha']).toBe('Bigger than the distance? Something is in the way.');
    expect(catalog['tier.hotshot']).toBe('Hotshot');
    expect(catalog['hub.play.first']).toBe('Play — First Shift');
    expect(catalog['daily.offline']).toBe("No dispatch — you're offline. Endless still works.");
    expect(catalog['play.wrong']).toBe(
      "All {n} breaks are down, but the fire disagrees with the report. Something's off.",
    );
    expect(catalog['streak.frozen']).toBe('Controlled burn — your streak held.');
    expect(catalog['coach.stage2.rest_must_be_breaks']).toBe(
      'Only the remaining cells can hold the missing breaks.',
    );
    expect(catalog['share.copied']).toBe('Copied.');
    expect(catalog['auth.consumed']).toBe('Signed in. Your record is protected.');
    expect(catalog['settings.delete']).toBe('Delete my account');
    expect(catalog['email.magic.subject']).toBe('Your Burnfront sign-in link');
    expect(catalog['a11y.cell.spark']).toBe('{cell}, the spark');
    expect(catalog['replay.previousMinute']).toBe('Previous minute');
    expect(catalog['error.rateLimited']).toBe('Too many requests — give it a minute.');
  });

  it('parses compact multi-key bullets and sibling shorthand', () => {
    expect(catalog['tier.lookout']).toBe('Lookout');
    expect(catalog['play.loading.endless.1']).toBe('Surveying terrain…');
    expect(catalog['play.loading.endless.4']).toBe('checking every break earns its place…');
  });

  it('strips markdown bold but preserves ICU internals', () => {
    expect(catalog['rules.1']).toBe(
      'Shade exactly {n} firebreaks. The ★ and the numbered cells are never breaks.',
    );
    expect(catalog['share.line2']).toContain('{clean, select, yes { · ✅ clean} other {}}');
  });

  it('keeps proposed keys quarantined from generated ones', () => {
    for (const key of Object.keys(proposedCatalog)) {
      expect(catalog).not.toHaveProperty(key);
    }
  });
});
