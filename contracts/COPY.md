# COPY.md — voice guide + canonical strings (FROZEN after ADR-0011)

Every user-facing string in `apps/` loads from the keyed catalog below through one
keyed-strings module (WS-09). EN only at launch; NL later is a translation file, not
a refactor. Keys are stable identifiers — changing a key is a contract change;
changing EN wording of an existing key is a normal PR.

## Voice

Calm night-shift dispatcher writing an incident report. Short declaratives, second
person, present tense. Numbers are facts, not celebrations.
**Lexicon:** contain, dispatch, incident, terrain, survey, crew, burn order,
wavefront, shift. **Banned:** fire puns ("hot streak", "you're on fire"),
exclamation marks in system copy, "awesome/great/well done", emoji outside the
share card. Dailies are titled **Incident #N**.
Interpolations use `{braces}`. Pluralization via ICU MessageFormat where marked.

## app

- `app.title` — Burnfront
- `app.tagline` — Every board is provably fair.
- `app.eyebrow` — Incident report · deduction puzzle

## rules (verbatim from the genre spec; shown on /rules and in the Academy)

- `rules.1` — **Shade exactly {n} firebreaks.** The ★ and the numbered cells are never breaks.
- `rules.2` — **Fire spreads one cell per minute.** It starts on the ★ at minute 0 and moves up, down, left and right — never diagonally, never through a break.
- `rules.3` — **Everything else burns.** Every cell that isn't a firebreak must be reached by the fire eventually. No safe pockets.
- `rules.4` — **Numbers are exact arrival times.** A cell marked 5 caught fire at minute 5 — not before, not after.
- `rules.note.distance` — A cell's minute is the length of the fire's shortest open route from the ★ — never less than the straight-line distance.
- `rules.note.aha` — Bigger than the distance? Something is in the way.
- `rules.note.wavefront` — Neighboring burnt cells differ by at most one minute; a cell burning at minute t caught it from a neighbor that burned at t−1.
- `rules.note.witnessed` — Every firebreak earns its place: if it were open, the fire would reach at least one numbered cell ahead of schedule.

## tiers

- `tier.lookout` — Lookout · `tier.crew` — Crew · `tier.hotshot` — Hotshot
- `tier.size` — {tier} {rows}×{cols}

## hub

- `hub.play.first` — Play — First Shift
- `hub.play.daily` — Play today's Burn Order
- `hub.play.daily.streak` — Day {n} — today's Burn Order
- `hub.play.resume` — Resume — {elapsed} elapsed
- `hub.play.endless` — Keep burning · {tier}
- `hub.play.resumeEndless` — Resume Endless
- `hub.lane.daily` — The Daily Burn Order
- `hub.lane.endless` — Endless — fresh terrain, generated on-site.
- `hub.lane.academy` — The Academy
- `hub.lane.record` — Your record
- `hub.lane.rush` — Rush — crews in training. Coming after launch.
- `hub.guest` — Guest
- `hub.countdown` — Next incident at midnight UTC — {hh}:{mm}:{ss}.
- `hub.endless.solved` — {n} contained this tier
- `hub.academy.progress` — {done}/{total} lessons

## daily

- `daily.title` — Incident #{n}
- `daily.solvedBy` — {count, plural, one {# crew has} other {# crews have}} contained Incident #{n}.
- `daily.rankFallback` — #{rank} to contain today's fire.
- `daily.pastBanner` — This is {weekday}'s incident — today's is live →
- `daily.offline` — No dispatch — you're offline. Endless still works.
- `daily.loading` — Fetching today's dispatch…

## board & play

- `play.loading` — Surveying terrain…
- `play.loading.endless.1` — Surveying terrain… · `.2` — placing breaks… · `.3` — verifying uniqueness… · `.4` — checking every break earns its place…
- `play.breaks` — Breaks {placed}/{n}
- `play.wrong` — All {n} breaks are down, but the fire disagrees with the report. Something's off.
- `play.contained` — CONTAINED
- `play.stats.time` — Contained in {time}.
- `play.stats.percentile` — Faster than {p}% of today's crews.
- `play.stats.clean` — Clean contain — no hints.
- `play.stats.ratingDelta` — {rating} ({delta})
- `play.stats.calibrating` — Calibrating — {n}/10
- `play.tomorrow` — Tomorrow: Incident #{n} · {tier} — starts at midnight UTC.
- `play.tomorrow.saturday` — Tomorrow: Incident #{n} · Hotshot 7×7 — Saturday's fire is the week's worst.

## streak

- `streak.days` — {n}-day streak
- `streak.frozen` — Controlled burn — your streak held.
- `streak.protect` — {n}-day streak. One cleared cache and it's gone. Protect it →
- `streak.protect.capped` — {n}-day streak in this browser. An account carries the last 7 days forward — and every day after.
- `streak.guestNote` — Solving as a guest — your record lives in this browser.

## coach (templates render engine DeductionReason kinds — vectors/README.md)

- `coach.stage1` — Look at the {m} at {cell}.
- `coach.stage2.clue_reached_too_fast` — If {cell} were open, the fire would reach the {m} too early.
- `coach.stage2.clue_unreachable_in_time` — If {cell} were a break, the {m} could no longer burn on time.
- `coach.stage2.open_cell_unreachable` — If {cell} were a break, some open ground could never burn.
- `coach.stage2.too_many_breaks` — Shading {cell} would use more than {n} breaks.
- `coach.stage2.not_enough_breaks_left` — Leaving {cell} open wouldn't leave room for {n} breaks.
- `coach.stage2.all_breaks_placed` — All {n} breaks are placed — everything else must burn.
- `coach.stage2.rest_must_be_breaks` — Only the remaining cells can hold the missing breaks.
- `coach.stage3` — Place it for me
- `coach.stage3.warning` — Unrated if used.
- `coach.offer` — Want a nudge from the Coach?

## share (client-generated; spoiler-free burn signature)

- `share.headline` — Burnfront — Incident #{n} CONTAINED
- `share.line2` — ⏱ {time}{clean, select, yes { · ✅ clean} other {}}{streak, plural, =0 {} =1 {} other { · 🔥 #}}
- `share.url` — burnfront.com/daily/{date}
- `share.copied` — Copied.
- Signature: one emoji per minute, by cells ignited that minute: 🟥 ≥4 · 🟧 2–3 · 🟨 1.

## account & settings

- `auth.request` — Get a sign-in link
- `auth.sent` — If that address exists, a link is on its way. It works once, for 15 minutes.
- `auth.consumed` — Signed in. Your record is protected.
- `account.merge.summary` — {solves} solves merged. {days}-day streak protected.
- `settings.export` — Export my data (JSON)
- `settings.delete` — Delete my account
- `settings.delete.explain` — Your profile, streak and rating are erased. Anonymous solve statistics remain in the aggregates.
- `settings.streakAlert` — Streak protection alerts — one email, only on days your streak would end unsolved.
- `settings.title` — Settings
- `auth.email` — Email address
- `auth.consuming` — Verifying your sign-in link…
- `auth.expired` — That link is no longer valid — links work once, for 15 minutes. Request a new one below.
- `auth.signOut` — Sign out
- `settings.sound` — Sound
- `settings.reducedMotion` — Reduced motion
- `settings.hideTimer` — Hide the timer
- `settings.highContrast` — High-contrast theme
- `settings.timezone` — Timezone
- `settings.timezone.hint` — Sets when streak protection alerts are sent. The daily still flips at midnight UTC.
- `settings.export.sent` — Export queued. A download link is on its way to your email. It works once, for 24 hours.
- `settings.delete.typeToConfirm` — Type {word} to confirm.
- `settings.delete.word` — DELETE
- `settings.delete.done` — Deletion queued. Your local record stays in this browser; you are solving as a guest again.
- `common.cancel` — Cancel
- `me.history` — Solve history
- `me.history.empty` — No solves on record yet.
- `me.history.more` — Load more
- `me.mode.endless` — Endless
- `me.mode.pack` — Pack
- `me.distributions.pending` — Solve-time distributions build as more incidents are contained.

## endless

- `endless.new` — New incident · {tier}
- `endless.rating.pending` — Rating update queued

## email (WS-21; text-first)

- `email.magic.subject` — Your Burnfront sign-in link
- `email.streak.subject` — Your {n}-day streak has {hours, plural, one {# hour} other {# hours}}.
- `email.streak.body` — Incident #{incident} is still burning. Your streak ends at midnight UTC. — Burnfront dispatch
- `email.streak.play` — Contain it: {playUrl}
- `email.streak.unsubscribe` — One click turns these alerts off: {unsubscribeUrl}
- `email.subscribed.subject` — Streak protection alerts are on.
- `email.subscribed.body` — Streak protection alerts are on for this account. One email, only on days your streak would end unsolved, sent near 20:00 your local time. The day itself still ends at midnight UTC.
- `email.subscribed.unsubscribe` — One click turns them off: {unsubscribeUrl}
- `email.deleted.subject` — Your Burnfront account is deleted.
- `email.deleted.body` — Deletion confirmed. This account and its identifying data are erased. Anonymous aggregate statistics survive without a link to you; the details are in the privacy policy: {privacyUrl}
- `email.deleted.noFurther` — No further email will be sent to this address.
- `email.export.subject` — Your Burnfront data export is ready
- `email.signature` — — Burnfront dispatch
- `email.unsubscribed.title` — Streak protection alerts are off
- `email.unsubscribed.body` — No further alert email will be sent to this account. Alerts can be turned back on from the in-game settings.
- `email.unsubscribed.back` — Back to the front page
- `email.unsubscribed.confirm` — Streak protection alerts are off.

## a11y (screen-reader announcements)

- `a11y.cell.empty` — {cell}, empty
- `a11y.cell.break` — {cell}, firebreak
- `a11y.cell.dot` — {cell}, marked clear
- `a11y.cell.clue` — {cell}, clue: burns at minute {m}
- `a11y.cell.spark` — {cell}, the spark
- `a11y.replay.minute` — Minute {t}: {count} cells burning.
- `a11y.contained` — Contained. {time}.

## replay

- `replay.watchAgain` — Watch the burn again
- `replay.nextMinute` — Next minute
- `replay.previousMinute` — Previous minute
- `a11y.board` — Terrain

## landing (client-rendered only; Blade marketing copy is exempt — ADR-0022)

- `landing.hero.solved` — That's the game. A new one drops every midnight →

## errors

- `error.offline` — You're offline. The board still works; syncing resumes later.
- `error.generic` — Something failed on our side. The report is filed.
- `error.rateLimited` — Too many requests — give it a minute.
