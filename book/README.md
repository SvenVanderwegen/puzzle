# Burnfront puzzle book

Print files for the paperback, built for Amazon KDP.

```
node --experimental-strip-types --no-warnings book/generate.ts 1   # writes book/data/volume-1.json
node book/build.mjs 1                                               # writes book/out/*.pdf
```

`generate.ts` uses the app's generator (`apps/mobile/src/engine/generator.ts`), so every
puzzle has exactly one answer that can be found without guessing. It is seeded per volume
and puzzle number: running it again gives the same book, and volume 2 gives new puzzles.
The 8x8 boards take a few minutes each.

`build.mjs` needs Playwright with Chromium and network access for the Google Fonts
(Bricolage Grotesque, Geist, Geist Mono). It writes two files:

- `burnfront-book-<n>-interior.pdf`: title page, imprint, the rules, a worked example,
  two warm-ups, how to use the book, one puzzle per page, the answers. One ink.
- `burnfront-book-<n>-cover.pdf`: back, spine and front on one sheet with 0.125 in bleed.
  The spine width follows the page count. The white box on the back is where KDP prints
  the barcode. Each volume shifts the three squares one step along the heat ramp.

The trim size is 5.5 x 8.5 in (a standard KDP size; A5 is not on KDP's list), set at the top of `build.mjs` together with the
margins. Placeholders still to fill in: `[YEAR]`, `[PUBLISHER]`, `[ISBN]` on page 2.
