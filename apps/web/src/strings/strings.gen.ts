// GENERATED from contracts/COPY.md — DO NOT EDIT (CLAUDE.md rule 7).
// Regenerate: pnpm --filter @burnfront/web strings:generate
// Freshness is CI-verified: pnpm --filter @burnfront/web strings:check
// Interpolation (`{braces}`, ICU plural/select) lives in ./icu.ts.

export const catalog = {
  'app.title': 'Burnfront',
  'app.tagline': 'Every board is provably fair.',
  'app.eyebrow': 'Incident report · deduction puzzle',
  'rules.1': 'Shade exactly {n} firebreaks. The ★ and the numbered cells are never breaks.',
  'rules.2':
    'Fire spreads one cell per minute. It starts on the ★ at minute 0 and moves up, down, left and right — never diagonally, never through a break.',
  'rules.3':
    "Everything else burns. Every cell that isn't a firebreak must be reached by the fire eventually. No safe pockets.",
  'rules.4':
    'Numbers are exact arrival times. A cell marked 5 caught fire at minute 5 — not before, not after.',
  'rules.note.distance':
    "A cell's minute is the length of the fire's shortest open route from the ★ — never less than the straight-line distance.",
  'rules.note.aha': 'Bigger than the distance? Something is in the way.',
  'rules.note.wavefront':
    'Neighboring burnt cells differ by at most one minute; a cell burning at minute t caught it from a neighbor that burned at t−1.',
  'rules.note.witnessed':
    'Every firebreak earns its place: if it were open, the fire would reach at least one numbered cell ahead of schedule.',
  'tier.lookout': 'Lookout',
  'tier.crew': 'Crew',
  'tier.hotshot': 'Hotshot',
  'tier.size': '{tier} {rows}×{cols}',
  'hub.play.first': 'Play — First Shift',
  'hub.play.daily': "Play today's Burn Order",
  'hub.play.daily.streak': "Day {n} — today's Burn Order",
  'hub.play.resume': 'Resume — {elapsed} elapsed',
  'hub.play.endless': 'Keep burning · {tier}',
  'hub.play.resumeEndless': 'Resume Endless',
  'hub.lane.daily': 'The Daily Burn Order',
  'hub.lane.endless': 'Endless — fresh terrain, generated on-site.',
  'hub.lane.academy': 'The Academy',
  'hub.lane.record': 'Your record',
  'hub.lane.rush': 'Rush — crews in training. Coming after launch.',
  'hub.guest': 'Guest',
  'hub.countdown': 'Next incident at midnight UTC — {hh}:{mm}:{ss}.',
  'daily.title': 'Incident #{n}',
  'daily.solvedBy':
    '{count, plural, one {# crew has} other {# crews have}} contained Incident #{n}.',
  'daily.rankFallback': "#{rank} to contain today's fire.",
  'daily.pastBanner': "This is {weekday}'s incident — today's is live →",
  'daily.offline': "No dispatch — you're offline. Endless still works.",
  'daily.loading': "Fetching today's dispatch…",
  'play.loading': 'Surveying terrain…',
  'play.loading.endless.1': 'Surveying terrain…',
  'play.loading.endless.2': 'placing breaks…',
  'play.loading.endless.3': 'verifying uniqueness…',
  'play.loading.endless.4': 'checking every break earns its place…',
  'play.breaks': 'Breaks {placed}/{n}',
  'play.wrong': "All {n} breaks are down, but the fire disagrees with the report. Something's off.",
  'play.contained': 'CONTAINED',
  'play.stats.time': 'Contained in {time}.',
  'play.stats.percentile': "Faster than {p}% of today's crews.",
  'play.stats.clean': 'Clean contain — no hints.',
  'play.stats.ratingDelta': '{rating} ({delta})',
  'play.stats.calibrating': 'Calibrating — {n}/10',
  'play.tomorrow': 'Tomorrow: Incident #{n} · {tier} — starts at midnight UTC.',
  'play.tomorrow.saturday':
    "Tomorrow: Incident #{n} · Hotshot 7×7 — Saturday's fire is the week's worst.",
  'streak.days': '{n}-day streak',
  'streak.frozen': 'Controlled burn — your streak held.',
  'streak.protect': "{n}-day streak. One cleared cache and it's gone. Protect it →",
  'streak.guestNote': 'Solving as a guest — your record lives in this browser.',
  'coach.stage1': 'Look at the {m} at {cell}.',
  'coach.stage2.clue_reached_too_fast':
    'If {cell} were open, the fire would reach the {m} too early.',
  'coach.stage2.clue_unreachable_in_time':
    'If {cell} were a break, the {m} could no longer burn on time.',
  'coach.stage2.open_cell_unreachable':
    'If {cell} were a break, some open ground could never burn.',
  'coach.stage2.too_many_breaks': 'Shading {cell} would use more than {n} breaks.',
  'coach.stage2.not_enough_breaks_left': "Leaving {cell} open wouldn't leave room for {n} breaks.",
  'coach.stage2.all_breaks_placed': 'All {n} breaks are placed — everything else must burn.',
  'coach.stage2.rest_must_be_breaks': 'Only the remaining cells can hold the missing breaks.',
  'coach.stage3': 'Place it for me',
  'coach.stage3.warning': 'Unrated if used.',
  'coach.offer': 'Want a nudge from the Coach?',
  'share.headline': 'Burnfront — Incident #{n} CONTAINED',
  'share.line2':
    '⏱ {time}{clean, select, yes { · ✅ clean} other {}}{streak, plural, =0 {} =1 {} other { · 🔥 #}}',
  'share.url': 'burnfront.com/daily/{date}',
  'share.copied': 'Copied.',
  'auth.request': 'Get a sign-in link',
  'auth.sent': 'If that address exists, a link is on its way. It works once, for 15 minutes.',
  'auth.consumed': 'Signed in. Your record is protected.',
  'account.merge.summary': '{solves} solves merged. {days}-day streak protected.',
  'settings.export': 'Export my data (JSON)',
  'settings.delete': 'Delete my account',
  'settings.delete.explain':
    'Your profile, streak and rating are erased. Anonymous solve statistics remain in the aggregates.',
  'settings.streakAlert':
    'Streak protection alerts — one email, only on days your streak would end unsolved.',
  'email.magic.subject': 'Your Burnfront sign-in link',
  'email.streak.subject': 'Your {n}-day streak has {hours} hours.',
  'email.streak.body':
    'Incident #{incident} is still burning. Your streak ends at midnight UTC. — Burnfront dispatch',
  'a11y.cell.empty': '{cell}, empty',
  'a11y.cell.break': '{cell}, firebreak',
  'a11y.cell.dot': '{cell}, marked clear',
  'a11y.cell.clue': '{cell}, clue: burns at minute {m}',
  'a11y.cell.spark': '{cell}, the spark',
  'a11y.replay.minute': 'Minute {t}: {count} cells burning.',
  'a11y.contained': 'Contained. {time}.',
  'replay.watchAgain': 'Watch the burn again',
  'replay.nextMinute': 'Next minute',
  'replay.previousMinute': 'Previous minute',
  'a11y.board': 'Terrain',
  'error.offline': "You're offline. The board still works; syncing resumes later.",
  'error.generic': 'Something failed on our side. The report is filed.',
  'error.rateLimited': 'Too many requests — give it a minute.',
} as const;

export type CatalogKey = keyof typeof catalog;
