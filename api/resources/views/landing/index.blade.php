{{--
  The landing page (WS-15). Section order is FIXED by product.md §2:
  hero → replay strip → three rules + aha → provably fair → social proof →
  footer CTA. Demo before explanation, proof before ask.
  COPY.md keys used verbatim where they exist (app.*, rules.*, daily.*,
  play.breaks, replay.nextMinute); the remaining lines are the landing copy
  spec'd word-for-word in product.md §2 (no COPY key yet — see STATUS.md).
--}}
@extends('landing.layout')

@section('title', 'Burnfront — the daily fire-containment logic puzzle')
@section('meta-description', "A genuinely new logic puzzle: deduce the firebreaks from the fire's arrival times. One provably unique solution daily. No guessing, ever.")
@section('canonical', $baseUrl.'/')

@section('head-extra')
<script type="application/ld+json">{!! $jsonLd !!}</script>
@endsection

@section('content')

<section class="bf-section bf-hero" id="hero" aria-labelledby="hero-heading">
  <div>
    <p class="bf-eyebrow">Incident report · deduction puzzle</p>
    <h1 id="hero-heading">Every board is provably fair.</h1>
    <p class="bf-lede">Burnfront is a new logic puzzle. Reconstruct the firebreaks from when the fire arrived. One solution, zero guessing — machine-checked, every day.</p>
    <p class="bf-cta-row">
      <a class="bf-cta" href="/daily">Play today's Burn Order</a>
      <a href="/rules">60-second rules</a>
    </p>
  </div>
  <div>
    {{-- The hero fixture board, JSON inlined (no fetch, no generation);
         hero.js hydrates it into the interactive ui-web <Board>. --}}
    <script type="application/json" id="bf-hero-board">{!! json_encode($hero['board'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR) !!}</script>
    <div data-bf-hero-mount>
      <div class="bf-hero-live">
        @include('landing.partials.static-board', [
            'rows' => $hero['board']['rows'],
            'cols' => $hero['board']['cols'],
            'spark' => $hero['board']['spark'],
            'clues' => $hero['board']['clues'],
        ])
        <p class="bf-hero-hud">
          <span class="bf-chip"><span class="bf-chip__value">Breaks 0/{{ $hero['board']['breaks'] }}</span></span>
        </p>
      </div>
    </div>
  </div>
</section>

<section class="bf-section bf-section--tight bf-strip" id="replay" data-bf-strip aria-labelledby="replay-heading">
  <h2 id="replay-heading" class="bf-visually-hidden-heading">The burn replay</h2>
  <p class="bf-lede">The payoff: watch your answer burn, minute by minute.</p>
  <div class="bf-strip-board">
    @include('landing.partials.strip-board', ['strip' => $strip])
  </div>
  <div class="bf-strip-controls">
    <span class="bf-chip"><span class="bf-chip__value" data-bf-strip-minute>{{ $strip['maxMinute'] }}</span></span>
    <button type="button" class="bf-button" data-bf-strip-step hidden>Next minute</button>
  </div>
</section>

<section class="bf-section" id="rules" aria-labelledby="rules-heading">
  <h2 id="rules-heading">Three rules.</h2>
  <ul class="bf-cards">
    <li class="bf-card">
      <p><strong>Shade exactly {{ $hero['board']['breaks'] }} firebreaks.</strong> The ★ and the numbered cells are never breaks.</p>
    </li>
    <li class="bf-card">
      <p><strong>Fire spreads one cell per minute.</strong> It starts on the ★ at minute 0 and moves up, down, left and right — never diagonally, never through a break.</p>
    </li>
    <li class="bf-card">
      <p><strong>Numbers are exact arrival times.</strong> A cell marked 5 caught fire at minute 5 — not before, not after.</p>
    </li>
    <li class="bf-card bf-card--aha">
      <p>Bigger than the distance? Something is in the way.</p>
    </li>
  </ul>
</section>

<section class="bf-section" id="fair" aria-labelledby="fair-heading">
  <h2 id="fair-heading">Provably fair.</h2>
  <ul class="bf-stamps">
    <li class="bf-stamp">
      <h3>Unique</h3>
      <p>An exact solver proves exactly one answer exists.</p>
    </li>
    <li class="bf-stamp">
      <h3>Guess-free</h3>
      <p>A deduction engine solves it with pure logic before we publish.</p>
    </li>
    <li class="bf-stamp">
      <h3>Every break earns its place</h3>
      <p>Open any firebreak and some clue burns too early.</p>
    </li>
  </ul>
  <p><a href="/about">How we prove it →</a></p>
</section>

<section class="bf-section bf-section--tight" id="crews" aria-labelledby="crews-heading">
  <h2 id="crews-heading" class="bf-visually-hidden-heading">Today's crews</h2>
  <p class="bf-counter" data-testid="social-proof">
    @if ($social['mode'] === 'count')
      {{-- COPY.md daily.solvedBy --}}
      <strong>{{ number_format($social['count']) }}</strong> {{ $social['count'] === 1 ? 'crew has' : 'crews have' }} contained Incident #{{ $social['incident'] }}.
    @else
      {{-- COPY.md daily.rankFallback (counts under 500 read as a rank) --}}
      <strong>#{{ $social['rank'] }}</strong> to contain today's fire.
    @endif
  </p>
</section>

<section class="bf-section bf-endcta" id="midnight" aria-labelledby="midnight-heading">
  <h2 id="midnight-heading">The fire starts at midnight.</h2>
  <p><a class="bf-cta" href="/daily">Play today's Burn Order</a></p>
</section>

<script type="module" src="/landing/hero.js?v={{ $heroModuleVersion }}"></script>
@endsection
