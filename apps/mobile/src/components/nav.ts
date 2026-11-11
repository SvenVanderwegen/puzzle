import { router } from 'expo-router';

import type { BoardSize } from '@/config';

/** Open the board for a fire. `replace` swaps the current board (Next fire). */
export const playDaily = (replace = false) => go({ kind: 'daily' }, replace);
export const playCampaign = (n: number, replace = false) => go({ kind: 'campaign', n: String(n) }, replace);
export const playQuick = (size: BoardSize, replace = false) => go({ kind: 'quick', size: String(size) }, replace);

function go(params: Record<string, string>, replace: boolean) {
  const to = { pathname: '/play' as const, params };
  if (replace) router.replace(to);
  else router.push(to);
}
