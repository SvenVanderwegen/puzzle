/**
 * PROPOSED COPY KEYS — quarantine for strings apps/web needs before they
 * exist in contracts/COPY.md (ADR-0017 / ADR-0023 / ADR-0026 pattern).
 *
 * WS-12 fills this with the Academy lesson scripts: titles, blurbs, the
 * beat-by-beat walkthrough captions, practice framing, the demo player
 * controls and the Certified badge. This is the largest single copy batch in
 * the project. Voice is the frozen COPY.md guide — calm night-shift
 * dispatcher, second person, present tense, short declaratives, no
 * exclamation marks, no fire puns; lexicon: contain, dispatch, incident,
 * terrain, survey, crew, burn order, wavefront, shift.
 *
 * The lead moves these into contracts/COPY.md by ADR at integration and
 * audits the voice, at which point each key leaves this file. Generated
 * catalog keys always win collisions (strings/index.ts merge order).
 */
export const proposedCatalog = {
  // ---- Academy index ------------------------------------------------------
  'academy.intro':
    'Seven short lessons. Each teaches one argument, then hands you two boards to practice it on.',
  'academy.certified': 'Certified',
  'academy.certified.note': 'All seven lessons contained. You hold the full toolkit.',
  'academy.lesson.done': 'Contained',
  'academy.capstoneBadge': 'Capstone',

  // ---- Demo player (shared controls) --------------------------------------
  'academy.demo.region': 'Guided walkthrough',
  'academy.demo.play': 'Play',
  'academy.demo.pause': 'Pause',
  'academy.demo.step': 'Next step',
  'academy.demo.back': 'Previous step',
  'academy.demo.replay': 'Watch again',
  'academy.demo.progress': 'Step {n} of {total}',
  'academy.demo.minute': 'Minute {t}',
  'academy.demo.minute.pre': 'Before ignition',

  // ---- Practice + lesson flow ---------------------------------------------
  'academy.begin': 'Begin practice',
  'academy.practice.heading': 'Practice — board {n} of {total}',
  'academy.practice.intro':
    'Shade the firebreaks that make every number exact. Same rules, live board.',
  'academy.practice.next': 'Next board',
  'academy.practice.finish': 'Finish the lesson',
  'academy.lesson.completeHeading': 'Lesson contained',
  'academy.lesson.completeNote':
    'You have the argument and the practice. It carries into every board from here.',
  'academy.lesson.next': 'Next: {title}',
  'academy.lesson.back': 'Back to the Academy',
  'academy.firstShift.toDaily': "Continue to today's Burn Order",
  'academy.firstShift.toDailyNote': 'Your first shift is logged. The daily incident is waiting.',

  // ---- Lesson 1 · First Shift ---------------------------------------------
  'academy.l1.title': 'First Shift',
  'academy.l1.blurb': 'How the fire moves, and how the numbers record it.',
  'academy.l1.beat.1':
    'Fire starts on the spark at minute zero. The dark cells are firebreaks; the fire never crosses them.',
  'academy.l1.beat.2':
    'Each minute, the fire steps one cell — up, down, left, or right. Never diagonally.',
  'academy.l1.beat.3':
    'The cell below the spark reads three. The fire reaches it at minute three, straight down open ground.',
  'academy.l1.beat.4':
    'This cell sits three steps from the spark, yet it reads five. The short route is walled, so the fire took the long way.',
  'academy.l1.beat.5': 'Everything that is not a firebreak burns. No cell is spared.',
  'academy.l1.beat.6':
    'On a live board the firebreaks are hidden. The numbers are your evidence, and they point to exactly one answer.',

  // ---- Lesson 2 · Too Fast Means Walls ------------------------------------
  'academy.l2.title': 'Too Fast Means Walls',
  'academy.l2.blurb': 'A clue reached too fast means a wall guards it.',
  'academy.l2.beat.1': 'Read the clue on the top edge. It burns at minute seven.',
  'academy.l2.beat.2':
    'Straight across, it is only three steps from the spark. Open ground would carry the fire there by minute three.',
  'academy.l2.beat.3':
    'Minute three is too fast for a clue that reads seven. Something on the short route stops the fire.',
  'academy.l2.beat.4': 'The short route is a firebreak. Shade it, and the fast path closes.',
  'academy.l2.beat.5':
    'Now the fire detours and arrives at seven, exactly as the clue records. A clue reached too fast means a wall.',

  // ---- Lesson 3 · Too Slow Means Roads ------------------------------------
  'academy.l3.title': 'Too Slow Means Roads',
  'academy.l3.blurb': 'A clue reached right on time means a road must stay open.',
  'academy.l3.beat.1': 'The far corner reads four — exactly its distance from the spark.',
  'academy.l3.beat.2': 'The only way to arrive that fast is a clear road straight up the edge.',
  'academy.l3.beat.3':
    'Shade any cell on that road and the corner can no longer burn in time. So every cell on it stays open.',
  'academy.l3.beat.4':
    'Kept clear, the road delivers the fire to the corner at four, on schedule. A clue this exact forces its road open.',

  // ---- Lesson 4 · Chains to the Spark -------------------------------------
  'academy.l4.title': 'Chains to the Spark',
  'academy.l4.blurb': 'Follow the wavefront back to the spark, one minute at a time.',
  'academy.l4.beat.1': 'The corner burns at minute eight. Work backward from it.',
  'academy.l4.beat.2':
    'A cell only catches fire from a neighbor that burned one minute earlier. Eight needs a seven beside it.',
  'academy.l4.beat.3':
    'Follow the drop — seven, six, five — each step one minute closer to the spark. That unbroken line is the road.',
  'academy.l4.beat.4':
    'Break the chain anywhere and the corner could never reach eight. The whole corridor back to the spark stays open.',

  // ---- Lesson 5 · Nothing Is Spared ---------------------------------------
  'academy.l5.title': 'Nothing Is Spared',
  'academy.l5.blurb': 'Every cell burns. No firebreak may seal a pocket.',
  'academy.l5.beat.1':
    'Every cell that is not a firebreak must burn. No pocket is ever left unreached.',
  'academy.l5.beat.2': 'Watch the top corner. The fire reaches it only through this open ground.',
  'academy.l5.beat.3':
    'Wall both of these cells and the corner is sealed. The fire could never arrive, and the report would be impossible.',
  'academy.l5.beat.4': 'So they stay open. A break that strands open ground is no break at all.',

  // ---- Lesson 6 · Counting the Endgame ------------------------------------
  'academy.l6.title': 'Counting the Endgame',
  'academy.l6.blurb': 'When the breaks are all placed, counting finishes the board.',
  'academy.l6.beat.1': 'This board asks for exactly four firebreaks.',
  'academy.l6.beat.2':
    'Here they are — four walls, and the board wanted four. The count is complete.',
  'academy.l6.beat.3':
    'With every break accounted for, nothing else can be one. Everything that remains must burn.',
  'academy.l6.beat.4':
    'Fill it in. Once the breaks are all placed, counting alone finishes the board.',

  // ---- Lesson 7 · The Long Way Around (capstone) --------------------------
  'academy.l7.title': 'The Long Way Around',
  'academy.l7.blurb': 'Read a clue that only the long way around can explain.',
  'academy.l7.beat.1': 'Two cells above the spark sits a clue reading eight.',
  'academy.l7.beat.2':
    'Two steps of open ground would bring the fire in two minutes. Eight is far too slow — the straight route is walled.',
  'academy.l7.beat.3': 'Wall by wall the short ways close, and the fire is forced out and around.',
  'academy.l7.beat.4':
    'It travels the long way — down, across, and back up — arriving at eight. Read the detour and the board opens.',
  'academy.l7.beat.5':
    'Too fast, too slow, the chain, the count — this board asks for all of it. Contain the capstone to finish your training.',
} as const;

export type ProposedKey = keyof typeof proposedCatalog;
