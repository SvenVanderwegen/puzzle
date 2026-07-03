/**
 * Accessibility assertions for the fixture page (WCAG 2.1 AA target).
 *
 * NOTE: the automated axe scan is NOT run here — @axe-core/playwright is the
 * allowlisted tool and it rides the WS-17 e2e suite against this same
 * fixture page. Until then this file asserts the axe-critical invariants
 * manually via roles/names/focus-order (testing-library), covering the
 * serious-violation classes axe would flag: missing accessible names,
 * broken grid semantics, no keyboard path, missing live regions, and
 * hold-to-reveal-only interactions.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { FixtureApp } from './fixture/FixtureApp';
import { renderBoard } from './testing/helpers';

describe('fixture page semantics', () => {
  it('has a single top-level heading', () => {
    render(<FixtureApp />);
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
  });

  it('exposes a named grid with complete row/gridcell structure', () => {
    render(<FixtureApp />);
    const grid = screen.getByRole('grid');
    expect(grid).toHaveAccessibleName('Terrain');
    expect(screen.getAllByRole('row')).toHaveLength(5);
    expect(screen.getAllByRole('gridcell')).toHaveLength(25);
  });

  it('gives every gridcell a non-empty accessible name (COPY a11y keys)', () => {
    render(<FixtureApp />);
    for (const cell of screen.getAllByRole('gridcell')) {
      expect(cell).toHaveAccessibleName();
    }
  });

  it('marks inert cells (spark, clues) aria-disabled', () => {
    render(<FixtureApp />);
    const disabled = screen
      .getAllByRole('gridcell')
      .filter((cell) => cell.getAttribute('aria-disabled') === 'true');
    expect(disabled).toHaveLength(6); // 1 spark + 5 clues
  });

  it('keeps a polite live region for announcements', () => {
    render(<FixtureApp />);
    const region = screen.getByRole('status');
    expect(region).toHaveAttribute('aria-live', 'polite');
  });

  it('focus order: one tab stop enters the grid, one tab leaves it', async () => {
    const user = userEvent.setup();
    render(<FixtureApp />);
    const cells = screen.getAllByRole('gridcell');
    expect(cells.filter((cell) => cell.tabIndex === 0)).toHaveLength(1);
    await user.tab();
    expect(cells[0]).toHaveFocus();
    await user.tab();
    // roving tabindex: the next tab exits the grid entirely
    expect(document.activeElement?.getAttribute('role')).not.toBe('gridcell');
  });

  it('offers no hold-to-reveal-only interaction: every mark state is reachable by single keypresses', async () => {
    const user = userEvent.setup();
    const { session } = renderBoard();
    await user.tab();
    // long-press alternative: X reaches break, . reaches dot, both toggle off
    await user.keyboard('x');
    expect(session.markAt(0)).toBe('break');
    await user.keyboard('x');
    await user.keyboard('.');
    expect(session.markAt(0)).toBe('dot');
    await user.keyboard('.');
    expect(session.markAt(0)).toBe('empty');
  });

  it('the fixture html document declares lang and a title', () => {
    const html = readFileSync(join(process.cwd(), 'fixture', 'index.html'), 'utf8');
    expect(html).toContain('<html lang="en">');
    expect(html).toMatch(/<title>.+<\/title>/);
  });
});
