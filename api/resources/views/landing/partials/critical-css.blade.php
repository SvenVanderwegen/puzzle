{{--
  WS-15 critical CSS, inlined (ADR-0009: HTML ≤60KB gz incl. CSS; zero
  external requests; system fonts only — the display token's Staatliches
  falls back to system-ui because no @font-face ships here).

  $boardCss = resources/landing/board.css: the :root --bf-* token variables
  (generated from contracts/design-tokens.json) + ui-web's component CSS, so
  the static server render and the hydrated React board share ONE stylesheet.
  Everything below uses var(--bf-*) tokens only — no raw hex (tripwire:
  LandingAssetsTest).
--}}
<style>
{!! $boardCss !!}
*, *::before, *::after { box-sizing: border-box; }
html { background: var(--bf-color-soot); }
body {
  margin: 0;
  font-family: var(--bf-font-body);
  font-size: 14px;
  line-height: 1.65;
  color: var(--bf-color-ash);
  font-variant-numeric: tabular-nums;
  -webkit-font-smoothing: antialiased;
}
h1, h2, h3 {
  font-family: var(--bf-font-display);
  font-weight: 600;
  color: var(--bf-color-paper);
  line-height: 1.05;
  margin: 0 0 var(--bf-space-3);
  text-wrap: balance;
}
h1 { font-size: clamp(52px, 11vw, 76px); letter-spacing: 0.01em; }
h2 { font-size: clamp(24px, 4vw, 32px); }
h3 { font-size: 17px; }
p { margin: 0 0 var(--bf-space-3); }
a { color: var(--bf-color-ember); text-decoration-thickness: 1px; text-underline-offset: 3px; }
a:focus-visible { outline: 2px solid var(--bf-color-flame); outline-offset: 2px; }
strong { color: var(--bf-color-paper); font-weight: 600; }

.bf-eyebrow {
  font-size: 11px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--bf-color-ash);
  margin: 0 0 var(--bf-space-2);
}
.bf-lede { font-size: 16px; max-width: 34em; }
.bf-section { max-width: 1080px; margin: 0 auto; padding: var(--bf-space-7) var(--bf-space-4); }
.bf-section--tight { padding-top: var(--bf-space-5); padding-bottom: var(--bf-space-5); }

.bf-masthead {
  max-width: 1080px;
  margin: 0 auto;
  padding: var(--bf-space-4);
  display: flex;
  align-items: baseline;
  gap: var(--bf-space-4);
  flex-wrap: wrap;
}
.bf-wordmark {
  font-family: var(--bf-font-display);
  font-size: 22px;
  color: var(--bf-color-paper);
  text-decoration: none;
  letter-spacing: 0.04em;
}
.bf-masthead nav { margin-left: auto; display: flex; gap: var(--bf-space-4); align-items: baseline; }
.bf-masthead nav a:not(.bf-cta) { color: var(--bf-color-ash); text-decoration: none; font-size: 13px; }
.bf-masthead nav a:not(.bf-cta):hover { color: var(--bf-color-paper); }

.bf-cta {
  display: inline-block;
  background: var(--bf-color-ember);
  color: var(--bf-color-soot);
  border: 1px solid var(--bf-color-ember-deep);
  border-radius: var(--bf-radius-control);
  padding: var(--bf-space-3) var(--bf-space-5);
  font-weight: 700;
  font-size: 15px;
  text-decoration: none;
}
.bf-cta:hover { background: var(--bf-color-flame); border-color: var(--bf-color-ember); }
.bf-cta:focus-visible { outline: 2px solid var(--bf-color-flame); outline-offset: 2px; }
.bf-masthead .bf-cta { padding: var(--bf-space-2) var(--bf-space-4); font-size: 13px; }
.bf-cta-row { display: flex; gap: var(--bf-space-4); align-items: center; flex-wrap: wrap; margin-top: var(--bf-space-5); }

.bf-hero { display: grid; gap: var(--bf-space-6); align-items: center; }
@media (min-width: 880px) { .bf-hero { grid-template-columns: 1.1fr 1fr; } }
/* Reserve the board's box (CLS ≤ 0.05): same classes pre/post hydration. */
.bf-hero .bf-board, .bf-strip-board .bf-board { width: min(92vw, 420px); }
.bf-hero-live { display: block; }
.bf-hero-hud { margin: var(--bf-space-3) 0 0; min-height: 44px; display: flex; gap: var(--bf-space-3); align-items: center; flex-wrap: wrap; }
.bf-hero-wrong { color: var(--bf-color-ash); max-width: 26em; }
.bf-hero-card { font-size: 16px; margin-top: var(--bf-space-3); }
.bf-static .bf-cell { cursor: default; }

.bf-strip-board { margin: var(--bf-space-4) 0; }
.bf-strip-controls { display: flex; gap: var(--bf-space-3); align-items: center; min-height: 44px; }
.bf-strip .bf-cell:not(.bf-cell--burn) .bf-cell__glyph--burn { visibility: hidden; }

.bf-cards { display: grid; gap: var(--bf-space-4); grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); margin: 0; padding: 0; list-style: none; }
.bf-card {
  background: var(--bf-color-char);
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-panel);
  padding: var(--bf-space-4) var(--bf-space-4) var(--bf-space-3);
}
.bf-card--aha { background: var(--bf-color-char2); border-color: var(--bf-color-break-border); }
.bf-card--aha p { color: var(--bf-color-paper); font-size: 15px; }

.bf-stamps { display: grid; gap: var(--bf-space-4); grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); margin: 0; padding: 0; list-style: none; }
.bf-stamp { border-left: 3px solid var(--bf-color-ember-deep); padding-left: var(--bf-space-4); }
.bf-stamp h3 { text-transform: uppercase; letter-spacing: 0.09em; color: var(--bf-color-flame); }

.bf-counter { font-size: clamp(20px, 3.4vw, 28px); font-family: var(--bf-font-display); color: var(--bf-color-paper); }
.bf-counter strong { color: var(--bf-color-ember); }

.bf-endcta { text-align: center; }
.bf-endcta h2 { font-size: clamp(32px, 6vw, 48px); }

.bf-footer { border-top: 1px solid var(--bf-color-line); margin-top: var(--bf-space-7); }
.bf-footer .bf-section { display: flex; gap: var(--bf-space-4); flex-wrap: wrap; padding-top: var(--bf-space-5); padding-bottom: var(--bf-space-5); }
.bf-footer a { color: var(--bf-color-ash); text-decoration: none; font-size: 13px; }
.bf-footer a:hover { color: var(--bf-color-paper); }

.bf-facts { margin: 0; }
.bf-facts dt { color: var(--bf-color-paper); font-weight: 600; margin-top: var(--bf-space-3); }
.bf-facts dd { margin: 0; }
.bf-prose { max-width: 44em; }
.bf-notes { padding-left: 1.2em; }
.bf-notes li { margin-bottom: var(--bf-space-2); }

.bf-visually-hidden-heading {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
