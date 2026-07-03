/**
 * PROPOSED COPY KEYS — quarantine for strings apps/web needs before they
 * exist in contracts/COPY.md.
 *
 * WS-14 additions (flagged in tasks/WS-14/STATUS.md; the lead amends COPY.md
 * by ADR — ADR-0017 precedent — at which point each key moves out of this
 * file). Voice: calm night-shift dispatcher — short declaratives, second
 * person, no exclamation marks. Generated catalog keys always win collisions.
 */
export const proposedCatalog = {
  // /login — magic-link request form + consumed-link landing (ADR-0003).
  // The catalog has the button (auth.request), the constant response
  // (auth.sent) and the success toast (auth.consumed); the form label and
  // the two consume states are new.
  'auth.email': 'Email address',
  'auth.consuming': 'Verifying your sign-in link…',
  'auth.expired':
    'That link is no longer valid — links work once, for 15 minutes. Request a new one below.',
  'auth.signOut': 'Sign out',
  // /settings — local preference toggles (product.md §1/§4) and the
  // account rows the catalog does not cover.
  'settings.sound': 'Sound',
  'settings.reducedMotion': 'Reduced motion',
  'settings.hideTimer': 'Hide the timer',
  'settings.highContrast': 'High-contrast theme',
  'settings.timezone': 'Timezone',
  'settings.timezone.hint':
    'Sets when streak protection alerts are sent. The daily still flips at midnight UTC.',
  'settings.export.sent':
    'Export queued. A download link is on its way to your email. It works once, for 24 hours.',
  'settings.delete.typeToConfirm': 'Type {word} to confirm.',
  'settings.delete.word': 'DELETE',
  'settings.delete.done':
    'Deletion queued. Your local record stays in this browser; you are solving as a guest again.',
  'common.cancel': 'Cancel',
  // /me — solve history + the distributions placeholder.
  'me.history': 'Solve history',
  'me.history.empty': 'No solves on record yet.',
  'me.history.more': 'Load more',
  'me.mode.endless': 'Endless',
  'me.mode.pack': 'Pack',
  'me.distributions.pending': 'Solve-time distributions build as more incidents are contained.',
} as const;

export type ProposedKey = keyof typeof proposedCatalog;
