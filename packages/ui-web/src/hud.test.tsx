/**
 * HUD pieces: <CluePill>, <BreaksCounter> (over-budget = danger class),
 * <MinuteCounter>.
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { BreaksCounter, CluePill, MinuteCounter } from './hud';
import { hudStrings } from './fixture/fixtureStrings';

describe('BreaksCounter', () => {
  it('renders the play.breaks string with counts interpolated', () => {
    render(<BreaksCounter placed={2} total={4} strings={hudStrings} />);
    expect(screen.getByTestId('breaks-counter')).toHaveTextContent('Breaks 2/4');
  });

  it('flags over-budget with the danger modifier (never at or under budget)', () => {
    const { rerender } = render(<BreaksCounter placed={4} total={4} strings={hudStrings} />);
    expect(screen.getByTestId('breaks-counter')).not.toHaveClass('bf-chip--over');
    rerender(<BreaksCounter placed={5} total={4} strings={hudStrings} />);
    expect(screen.getByTestId('breaks-counter')).toHaveClass('bf-chip--over');
  });
});

describe('MinuteCounter', () => {
  it('shows the minute, tabular, or a dash before the burn starts', () => {
    const { rerender } = render(<MinuteCounter minute={null} />);
    expect(screen.getByTestId('minute-counter')).toHaveTextContent('–');
    rerender(<MinuteCounter minute={7} />);
    expect(screen.getByTestId('minute-counter')).toHaveTextContent('7');
  });
});

describe('CluePill', () => {
  it('renders the clue minute and takes an accessible label', () => {
    render(<CluePill minute={5} label="C3, clue: burns at minute 5" />);
    expect(screen.getByLabelText('C3, clue: burns at minute 5')).toHaveTextContent('5');
  });

  it('marks an on-time hit', () => {
    const { container, rerender } = render(<CluePill minute={5} />);
    expect(container.querySelector('.bf-clue-pill--hit')).toBeNull();
    rerender(<CluePill minute={5} hit />);
    expect(container.querySelector('.bf-clue-pill--hit')).not.toBeNull();
  });
});
