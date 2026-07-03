{{--
  /rules (WS-15, product.md §1): the indexable how-to-play page — the four
  rules and the reading-the-numbers primer, verbatim from contracts/COPY.md
  (rules.* keys; {n} reads as N on this board-less page), plus the pointer
  into the SPA academy.
--}}
@extends('landing.layout')

@section('title', 'How to play Burnfront — the rules')
@section('meta-description', 'The four rules of Burnfront: shade exactly N firebreaks, fire spreads one cell per minute, everything else burns, and the numbers are exact arrival times. Plus how to read the numbers.')
@section('canonical', $baseUrl.'/rules')

@section('content')

<section class="bf-section bf-prose">
  <p class="bf-eyebrow">Incident report · deduction puzzle</p>
  <h1>How to play</h1>
  <p class="bf-lede">A board is a night map. The ★ is where the fire started; the numbers say when it arrived. Your job is to work out where the firebreaks must have been.</p>
</section>

<section class="bf-section bf-section--tight" aria-labelledby="rules-heading">
  <h2 id="rules-heading">The rules</h2>
  <ul class="bf-cards">
    <li class="bf-card"><p><strong>Shade exactly N firebreaks.</strong> The ★ and the numbered cells are never breaks.</p></li>
    <li class="bf-card"><p><strong>Fire spreads one cell per minute.</strong> It starts on the ★ at minute 0 and moves up, down, left and right — never diagonally, never through a break.</p></li>
    <li class="bf-card"><p><strong>Everything else burns.</strong> Every cell that isn't a firebreak must be reached by the fire eventually. No safe pockets.</p></li>
    <li class="bf-card"><p><strong>Numbers are exact arrival times.</strong> A cell marked 5 caught fire at minute 5 — not before, not after.</p></li>
  </ul>
</section>

<section class="bf-section bf-section--tight bf-prose" aria-labelledby="numbers-heading">
  <h2 id="numbers-heading">Reading the numbers</h2>
  <ul class="bf-notes">
    <li>A cell's minute is the length of the fire's shortest open route from the ★ — never less than the straight-line distance.</li>
    <li>Bigger than the distance? Something is in the way.</li>
    <li>Neighboring burnt cells differ by at most one minute; a cell burning at minute t caught it from a neighbor that burned at t−1.</li>
    <li>Every firebreak earns its place: if it were open, the fire would reach at least one numbered cell ahead of schedule.</li>
  </ul>
  <p>The Academy teaches each of these moves hands-on, one lesson at a time: <a href="/academy">start with lesson one →</a></p>
  <p><a class="bf-cta" href="/daily">Play today's Burn Order</a></p>
</section>

@endsection
