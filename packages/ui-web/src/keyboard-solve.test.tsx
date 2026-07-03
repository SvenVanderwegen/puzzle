/**
 * Keyboard-only full solve (brief acceptance): the fixture puzzle is solved
 * start-to-finish with arrows + X alone, the session validates, and on the
 * fixture page the burn replay + CONTAINED finale follow — still keyboard-only.
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { UserEvent } from '@testing-library/user-event';
import { FixtureApp } from './fixture/FixtureApp';
import { fixtureBoard, fixtureBreakIndices } from './fixture/fixtureBoard';
import { renderBoard } from './testing/helpers';

/** Walk the roving focus from one row-major index to another with arrows. */
async function moveFocus(user: UserEvent, from: number, to: number): Promise<void> {
  const cols = fixtureBoard.cols;
  let r = Math.floor(from / cols);
  let c = from % cols;
  const tr = Math.floor(to / cols);
  const tc = to % cols;
  while (r < tr) {
    await user.keyboard('{ArrowDown}');
    r++;
  }
  while (r > tr) {
    await user.keyboard('{ArrowUp}');
    r--;
  }
  while (c < tc) {
    await user.keyboard('{ArrowRight}');
    c++;
  }
  while (c > tc) {
    await user.keyboard('{ArrowLeft}');
    c--;
  }
}

async function solveWithKeyboard(user: UserEvent): Promise<void> {
  await user.tab(); // into the grid (roving tabindex, cell A1)
  let position = 0;
  for (const target of fixtureBreakIndices) {
    await moveFocus(user, position, target);
    await user.keyboard('x');
    position = target;
  }
}

describe('keyboard-only solve', () => {
  it('places every break with arrows + X and the session validates', async () => {
    const user = userEvent.setup();
    const { session } = renderBoard();
    await solveWithKeyboard(user);
    expect(session.breaksPlaced).toBe(fixtureBoard.breaks);
    const result = session.completion();
    expect(result).not.toBeNull();
    expect(result?.valid).toBe(true);
    expect(result?.reason).toBe('ok');
  });

  it('on the fixture page: solve -> replay -> CONTAINED, keyboard-only', async () => {
    const user = userEvent.setup();
    render(<FixtureApp reducedMotion />);
    await solveWithKeyboard(user);

    // The board yields to the burn replay (reduced motion = stepper).
    const replay = await screen.findByTestId('burn-replay');
    expect(replay).toBeInTheDocument();

    // Step through every minute with the keyboard: tab lands on the Next
    // button (Previous is disabled at minute 0), Enter activates it.
    await user.tab();
    const next = screen.getByRole('button', { name: 'Next minute' });
    expect(next).toHaveFocus();
    let guard = 100;
    while (!(next as HTMLButtonElement).disabled && guard-- > 0) {
      await user.keyboard('{Enter}');
    }
    expect(screen.getByTestId('contained-stamp')).toHaveTextContent('CONTAINED');
    expect(screen.getByRole('status')).toHaveTextContent(/^Contained\./);
  });
});
