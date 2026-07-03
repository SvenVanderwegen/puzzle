/**
 * The landing page's board stylesheet, shared with the SPA byte-for-byte
 * (WS-15). scripts/build-landing.mjs ssr-loads this module and writes the
 * result to api/resources/landing/board.css, which the Blade layout inlines
 * as critical CSS — so the server-rendered static hero, the replay strip and
 * the hydrated ui-web <Board>/<BurnReplay> are styled by the SAME rules
 * (tokens come from contracts/design-tokens.json via ui-web's tokens.ts; no
 * raw hex anywhere). Freshness is enforced by `pnpm budget:landing` and by
 * the api-side Pest checker (LandingAssetsTest).
 */
export { burnColor, motion } from '@burnfront/ui-web';
import { tokensCssText, uiWebCss } from '@burnfront/ui-web';

export function landingBoardCss(): string {
  return `${tokensCssText()}\n${uiWebCss}`;
}
