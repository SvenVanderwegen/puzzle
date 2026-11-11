# Burnfront for iPhone (Expo)

The Burnfront mobile app, built with Expo SDK 58 and Expo Router. Native iOS
chrome around a board drawn in the Burnfront look ("Progressiekaart").

- **Native:** tab bar (Today, Campaign, Practice, You) via `expo-router/native-tabs`,
  stack navigation with large titles, the result as a form sheet, system
  switches, a SwiftUI segmented control and time picker (`@expo/ui`), the system
  share sheet, haptics on every move (`expo-haptics`), soft sound effects
  (`expo-audio`, they follow the silent switch and mix with the player's own
  music), SF Symbols, Dynamic Type.
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

## Test it on an iPhone

**Quick, free (Expo Go):** install *Expo Go* from the App Store, then on a Mac or
PC on the same wifi run `npx expo start` in this folder and scan the QR code
with the iPhone camera.

**As a real app (TestFlight):** needs an Apple Developer account and a free
Expo account. Once:

```bash
npx eas-cli@latest login
npx eas-cli@latest init            # links the project to your Expo account
```

Then for every new version:

```bash
npx eas-cli@latest build --platform ios --profile production --auto-submit
```

EAS builds in the cloud (no Xcode needed), asks for your Apple login the first
time to create the certificates, and sends the build to TestFlight. Install the
TestFlight app on the iPhone to play it.

### Build from GitHub

The workflow **Mobile app to TestFlight** (`.github/workflows/mobile-testflight.yml`)
does the same build without your computer. Start it under Actions > Mobile app
to TestFlight > Run workflow. It runs the checks, builds on Expo's servers and
sends the build to TestFlight (about 30 minutes, plus Apple's processing).

Set these once under the repository's Settings > Secrets and variables > Actions:

| Secret | Where to find it |
| --- | --- |
| `EXPO_TOKEN` | expo.dev > Account settings > Access tokens > Create token |
| `APPLE_TEAM_ID` | developer.apple.com > Account > Membership details > Team ID |
| `ASC_KEY_ID` | App Store Connect > Users and Access > Integrations > App Store Connect API > Generate API key (role **Admin**); the Key ID of that key |
| `ASC_ISSUER_ID` | The Issuer ID shown above the list of keys on that same page |
| `ASC_API_KEY_P8` | The whole text of the `.p8` file you download for that key (it can only be downloaded once) |
| `ASC_APP_ID` | App Store Connect > Apps > + > New App with bundle id `com.burnfront.app`; then App Information > Apple ID (a number) |

For a company developer account, also add the variable `APPLE_TEAM_TYPE` with
the value `COMPANY_OR_ORGANIZATION` (the default is `INDIVIDUAL`). With the API
key, EAS makes the signing certificate and profile itself, so no Apple login or
Mac is needed. If the first build stops at credentials, run the `eas build`
command above once from a computer; later runs from GitHub then reuse them.

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
- **Sounds:** `assets/sounds/*.wav` are made by `node scripts/make-sounds.mjs`
  in the repository root, together with the website's copies. Change a sound
  there, then run the script again.
- **Numbers to tune:** board sizes, the campaign ladder, the weekly goal and
  the level step are in `src/config.ts`. The weekly goal (5) and levels (one
  per 5 fires) are a proposal, not confirmed yet.

## Not done yet

- The app's daily fire is its own board, not the website's daily (the site's
  daily is made on the server with a secret and needs a login). Sharing one
  daily between site and app needs a small public API on the Laravel side.
- Only English so far. The bundle id `com.burnfront.app` is a placeholder; change it in `app.json` before the first TestFlight build if you want another one.
