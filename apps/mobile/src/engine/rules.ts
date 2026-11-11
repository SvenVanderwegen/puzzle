/**
 * The game rules, shared with the website. This re-exports
 * resources/js/lib/burnfront-engine.js unchanged (metro.config.js lets the
 * bundler read that folder), so the app and the site can never disagree on
 * when a fire is contained.
 */
export { buildAdj, bfs, validate, cellName, fmtClock, COLS } from '../../../../resources/js/lib/burnfront-engine.js';
