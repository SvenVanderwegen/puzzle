# Burnfront

A wildfire incident-reconstruction deduction puzzle. See [`docs/concept.md`](docs/concept.md)
for the premise and lore.

## Repo layout

- `app/`, `routes/`, `resources/`, etc. — the Laravel 13 app. Puzzle generation
  and solving live in `app/Support/Burnfront/`:
  - `Puzzle.php` — the board value object (grid, spark, clues, break count).
  - `Engine.php` — the algorithm: BFS burn times, the uniqueness solver, the
    no-guessing deduction solver, and the generator. A faithful PHP port of
    `reference/firebreak.py`.
  - `SeededRandom.php` — a small deterministic PRNG usable as `Engine::generate()`'s
    `random` callable (what a future daily puzzle would seed from the date).
  - `PuzzleService.php` — difficulty tiers and wire-format serialization; the
    seam a future scoreboard or daily-puzzle feature would build on.
  - The frontend is Inertia + Vue 3, styled with Tailwind CSS 4:
    - `resources/js/Pages/Burnfront/Index.vue` — the board, toolbar and page
      chrome. `resources/js/lib/burnfront-engine.js` validates a player's
      marking locally (instant feedback, no round trip); puzzle generation
      itself is server-authoritative via `GET /puzzle?difficulty=...`.
    - `resources/js/Pages/Burnfront/HowItWorksDemo.vue` — the scripted
      walkthrough on the page, with no dependency on the real engine.
    - `resources/css/app.css` — the Tailwind theme (colors, fonts) and the
      `bf-*` component classes the board/demo use.
- `reference/` — the frozen prototype (`firebreak.py` + a self-contained
  `index.html`) that `app/Support/Burnfront/Engine.php` is ported from. Do not
  edit; it's the ground truth other implementations are checked against.
- `docs/` — concept and design docs.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build # or `npm run dev` alongside `php artisan serve`
php artisan serve
```

## Tests

```bash
php artisan test
```

`tests/Unit/Support/Burnfront/EngineTest.php` checks the PHP engine against
the same fixed instance `reference/firebreak.py` ships as its README example,
plus seeded end-to-end generations. `tests/Feature/BurnfrontTest.php` covers
the HTTP surface.
