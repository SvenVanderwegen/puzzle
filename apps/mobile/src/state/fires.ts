/**
 * Getting fires ready ahead of time, so "Play" and "Next fire" open at once.
 * A quick fire takes a few seconds to build, so one of each size is kept
 * ready in memory.
 */
import { useEffect, useState } from 'react';

import type { BoardSize } from '@/config';
import { localDate, makeDaily, makeQuick, type Fire } from '@/engine/puzzles';

const ready = new Map<BoardSize, Promise<Fire>>();

export function quickFire(size: BoardSize): Promise<Fire> {
  const fire = ready.get(size) ?? makeQuick(size);
  ready.delete(size);
  // Start building the next one of this size straight away.
  setTimeout(() => ready.set(size, makeQuick(size)), 400);
  return fire;
}

export function warmQuick(size: BoardSize) {
  if (!ready.has(size)) ready.set(size, makeQuick(size));
}

const dailies = new Map<string, Promise<Fire>>();

export function dailyFire(date = localDate()): Promise<Fire> {
  let f = dailies.get(date);
  if (!f) {
    f = makeDaily(date);
    dailies.set(date, f);
  }
  return f;
}

/** Today's fire for the home screen; changes over at midnight while the app is open. */
export function useDailyFire(date: string): Fire | null {
  const [fire, setFire] = useState<Fire | null>(null);
  useEffect(() => {
    let live = true;
    dailyFire(date).then((f) => live && setFire(f));
    return () => {
      live = false;
    };
  }, [date]);
  return fire && fire.key === date ? fire : null;
}
