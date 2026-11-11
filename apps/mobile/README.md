# Burnfront for iPhone (Expo)

The Burnfront mobile app, built with Expo SDK 58 and Expo Router. Native iOS
chrome around a board drawn in the Burnfront look ("Progressiekaart").

- **Native:** tab bar (Today, Campaign, Practice, You) via `expo-router/native-tabs`,
  stack navigation with large titles, the result as a form sheet, system
  switches, a SwiftUI segmented control and time picker (`@expo/ui`), the system
  share sheet, haptics on every move (`expo-haptics`), SF Symbols, Dynamic Type.
- **Brand:** the board, numbers and fire names use Bricolage Grotesque and the
  heat ramp (colour = the minute the fire arrived).
- **Offline:** no account and no server. Every mode works without a connection.

## Run it

```bash
cd apps/mobile
npm install
npx expo run:ios          # development build in the iOS simulator (needs a Mac with Xcode)
# or: npx eas-cli@latest build --profile development --platform ios
```

`@expo/ui`, `expo-sqlite` and `expo-notifications` contain native code, so use a
development build rather than Expo Go.

## Checks

```bash
npm run typecheck   # tsc
npm run lint        # expo lint
npm test            # engine tests with node's test runner (no simulator needed)
```

## How it fits the repo

- **Rules:** `src/engine/rules.ts` re-exports `resources/js/lib/burnfront-engine.js`
  unchanged (see `metro.config.js`), so the app and the website judge a board
  the same way.
- **Generator:** `src/engine/generator.ts` is a typed port of the generator in
  `reference/index.html`. Seeded fires can stop after a fixed amount of work
  instead of a time limit, so every phone builds the same board.
- **Fires:** the 20 campaign fires and the first 400 daily fires ship in
  `src/data/*.json`, made by `npm run build-fires`. A later daily is built on
  the phone with the same seed (the test suite checks the two agree). Quick
  fires are generated fresh; one of each size is kept ready in the background.
- **Words:** every string is in `src/i18n/en.ts`. To add a language, copy it
  (for example to `nl.ts`), translate the values and register it in
  `src/i18n/index.ts`. The phone's language is picked automatically.
- **Numbers to tune:** board sizes, the campaign ladder, the weekly goal and
  the level step are in `src/config.ts`. The weekly goal (5) and levels (one
  per 5 fires) are a proposal, not confirmed yet.

## Not done yet

- The app's daily fire is its own board, not the website's daily (the site's
  daily is made on the server with a secret and needs a login). Sharing one
  daily between site and app needs a small public API on the Laravel side.
- Only English so far, and no App Store build profile (`eas.json`) yet.
