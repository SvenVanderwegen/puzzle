/**
 * Where each kind of fire comes from. Everything works offline:
 * - campaign fires ship inside the app (src/data/campaign.json),
 * - daily fires ship for the first year (src/data/daily.json); a later date
 *   is generated on the phone with the same seed and the same fixed amount
 *   of work, so every phone still gets the same board,
 * - a quick fire is generated fresh every time.
 * Both JSON files come from scripts/build-fires.ts.
 */
import { CAMPAIGN } from '../config';
import campaignData from '../data/campaign.json';
import dailyData from '../data/daily.json';
import { daysBetween, generateDaily, unpack, dailyNumber, dailyPlace, campaignPlace, type Fire, type Packed } from './fires';

export * from './fires';

export const CAMPAIGN_SIZE = CAMPAIGN.length;

/** The daily fire if it ships with the app, without any waiting. */
export function bundledDaily(date: string): Fire | null {
  const k = daysBetween(dailyData.from, date);
  const row = k >= 0 ? (dailyData.fires as Packed[])[k] : undefined;
  if (!row) return null;
  return { kind: 'daily', key: date, place: dailyPlace(date), number: dailyNumber(date), puzzle: unpack(row) };
}

export async function makeDaily(date: string): Promise<Fire> {
  return bundledDaily(date) ?? generateDaily(date);
}

export function campaignFire(n: number): Fire {
  const row = (campaignData as Packed[])[n - 1];
  if (!row) throw new Error(`No campaign fire ${n}`);
  return { kind: 'campaign', key: String(n), place: campaignPlace(n), number: n, puzzle: unpack(row) };
}
