/**
 * Numbers that shape the game, kept in one place so they are easy to tune.
 * The weekly goal and the level system are a proposal Sven has not
 * confirmed yet: change them here, nowhere else.
 */

export type BoardSize = 5 | 6 | 7 | 8;

export type FireShape = {
  rows: number;
  cols: number;
  breaks: number;
  minClues: number;
  /** Wall-clock budget for a fresh (unseeded) fire. */
  budgetMs: number;
  /** Work budget for a seeded fire, so every phone builds the same board. */
  workBudget: number;
};

/** Quick fire sizes, same shapes as the website's difficulty tiers. */
export const QUICK_FIRES: Record<BoardSize, FireShape> = {
  5: { rows: 5, cols: 5, breaks: 4, minClues: 5, budgetMs: 4000, workBudget: 160 },
  6: { rows: 6, cols: 6, breaks: 8, minClues: 8, budgetMs: 6000, workBudget: 220 },
  7: { rows: 7, cols: 7, breaks: 12, minClues: 12, budgetMs: 9000, workBudget: 280 },
  8: { rows: 8, cols: 8, breaks: 17, minClues: 17, budgetMs: 14000, workBudget: 340 },
};

export const QUICK_SIZES: BoardSize[] = [5, 6, 7, 8];

/** The daily fire: one board for everybody, 6 by 6 like on the website. */
export const DAILY_FIRE: FireShape = QUICK_FIRES[6];

/** Daily number 1 is this date. */
export const DAILY_EPOCH = '2026-11-10';

/** Campaign fires, same ladder as app/Support/Burnfront/CampaignService.php. */
export const CAMPAIGN: FireShape[] = [
  { rows: 5, cols: 5, breaks: 3, minClues: 7, budgetMs: 3000, workBudget: 120 },
  { rows: 5, cols: 5, breaks: 3, minClues: 6, budgetMs: 3300, workBudget: 130 },
  { rows: 5, cols: 5, breaks: 4, minClues: 6, budgetMs: 3700, workBudget: 140 },
  { rows: 5, cols: 5, breaks: 4, minClues: 5, budgetMs: 4000, workBudget: 160 },
  { rows: 6, cols: 6, breaks: 6, minClues: 10, budgetMs: 4500, workBudget: 180 },
  { rows: 6, cols: 6, breaks: 6, minClues: 9, budgetMs: 5000, workBudget: 190 },
  { rows: 6, cols: 6, breaks: 7, minClues: 9, budgetMs: 5500, workBudget: 200 },
  { rows: 6, cols: 6, breaks: 8, minClues: 8, budgetMs: 6000, workBudget: 220 },
  { rows: 7, cols: 7, breaks: 9, minClues: 15, budgetMs: 6500, workBudget: 230 },
  { rows: 7, cols: 7, breaks: 10, minClues: 13, budgetMs: 7300, workBudget: 250 },
  { rows: 7, cols: 7, breaks: 11, minClues: 13, budgetMs: 8100, workBudget: 260 },
  { rows: 7, cols: 7, breaks: 12, minClues: 12, budgetMs: 9000, workBudget: 280 },
  { rows: 8, cols: 8, breaks: 13, minClues: 21, budgetMs: 9500, workBudget: 300 },
  { rows: 8, cols: 8, breaks: 14, minClues: 19, budgetMs: 11000, workBudget: 310 },
  { rows: 8, cols: 8, breaks: 16, minClues: 18, budgetMs: 12500, workBudget: 330 },
  { rows: 8, cols: 8, breaks: 17, minClues: 17, budgetMs: 14000, workBudget: 340 },
  { rows: 7, cols: 7, breaks: 11, minClues: 9, budgetMs: 10000, workBudget: 300 },
  { rows: 7, cols: 7, breaks: 12, minClues: 6, budgetMs: 13000, workBudget: 340 },
  { rows: 8, cols: 8, breaks: 15, minClues: 8, budgetMs: 13000, workBudget: 380 },
  { rows: 8, cols: 8, breaks: 17, minClues: 5, budgetMs: 15000, workBudget: 420 },
];

/** Campaign chapters: which fires belong together in the list. */
export const CAMPAIGN_CHAPTERS: { key: string; fires: number[] }[] = [
  { key: 'first', fires: [1, 2, 3, 4] },
  { key: 'six', fires: [5, 6, 7, 8] },
  { key: 'seven', fires: [9, 10, 11, 12] },
  { key: 'eight', fires: [13, 14, 15, 16] },
  { key: 'fewer', fires: [17, 18, 19, 20] },
];

/** Proposal: fires to contain in a week (Monday to Sunday). */
export const WEEKLY_GOAL = 5;

/** Proposal: one level up for every this many fires contained. */
export const FIRES_PER_LEVEL = 5;
