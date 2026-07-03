{{--
  /about (WS-15, product.md §1): the provably-fair story — the three
  guarantees in plain language, then the generation math (condensed from
  docs/GENRE.md), then the press-kit anchor.
--}}
@extends('landing.layout')

@section('title', 'About Burnfront — how every board is proven fair')
@section('meta-description', 'The three fairness guarantees behind Burnfront — a provably unique solution, a certified guess-free solving path, and a witness for every firebreak — and the generation math that enforces them.')
@section('canonical', $baseUrl.'/about')

@section('content')

<section class="bf-section bf-prose">
  <p class="bf-eyebrow">Incident report · deduction puzzle</p>
  <h1>Every board is provably fair.</h1>
  <p class="bf-lede">Most puzzle generators hope their boards are solvable. Burnfront does not hope. Every board ships with a machine-checked certificate of three guarantees.</p>
</section>

<section class="bf-section bf-section--tight bf-prose" aria-labelledby="guarantees-heading">
  <h2 id="guarantees-heading">The three guarantees</h2>
  <ul class="bf-stamps">
    <li class="bf-stamp">
      <h3>Unique</h3>
      <p>Exactly one arrangement of firebreaks fits the clues. An exact solver — an exhaustive search that cannot miss an answer — proves it before the board is published. Not "we didn't find a second solution": there is none.</p>
    </li>
    <li class="bf-stamp">
      <h3>Guess-free</h3>
      <p>A deduction engine must solve the board using single-cell logic only — "if this cell were open, the 5 would burn too soon." No trial and error, no backtracking. If pure reasoning cannot finish the board, the board does not ship.</p>
    </li>
    <li class="bf-stamp">
      <h3>Every break is witnessed</h3>
      <p>For every firebreak in the answer, opening it would let the fire reach at least one printed clue ahead of schedule. You can always point at the clue a break protects — no break is justified only by "the count says so."</p>
    </li>
  </ul>
</section>

<section class="bf-section bf-section--tight bf-prose" aria-labelledby="math-heading">
  <h2 id="math-heading">How a board is made</h2>
  <p>Generation runs solution-first. The terrain comes before the clues:</p>
  <ol class="bf-notes">
    <li><strong>Build a full solution.</strong> Place the spark and the firebreaks so that the open cells form one connected region — everything that can burn, burns. Burn times are breadth-first-search distances from the spark.</li>
    <li><strong>Repair until every break matters.</strong> Any break whose removal would change no cell's burn time is relocated. A silent break could only be found by exhausting the count, which feels arbitrary to a solver — so silent breaks are not allowed to exist.</li>
    <li><strong>Start from every clue, then delete.</strong> With a clue on every open cell the board is trivially unique. Clues are then removed greedily — and a clue only stays removed if three oracles still pass: the exact counter re-proves the solution is unique, the deduction engine still solves the board with pure logic, and every break still has a clue as its witness.</li>
  </ol>
  <p>Uniqueness is therefore an invariant of the loop, not a post-hoc hope. The exact counter is an exhaustive search whose pruning rules never discard a completable state; the deduction oracle uses exactly the single-cell refutations a human uses. The reference implementation, its test vectors and this site's engine agree on every published board — the vectors are the law the code is held to.</p>
</section>

<section class="bf-section bf-section--tight bf-prose" id="press-kit" aria-labelledby="press-heading">
  <h2 id="press-heading">Press kit</h2>
  <dl class="bf-facts">
    <dt>What</dt>
    <dd>Burnfront — a daily fire-containment logic puzzle. Deduce the firebreaks from the fire's arrival times.</dd>
    <dt>Cadence</dt>
    <dd>One incident per day, published at midnight UTC. Endless boards generated on demand.</dd>
    <dt>Tiers</dt>
    <dd>Lookout 5×5 · Crew 6×6 · Hotshot 7×7.</dd>
    <dt>The claim</dt>
    <dd>Every board is provably fair: a unique solution, a certified guess-free solving path, and a witness for every firebreak.</dd>
    <dt>Play</dt>
    <dd><a href="/daily">burnfront.com/daily</a> — no account required.</dd>
    <dt>Contact</dt>
    <dd><a href="/imprint">Imprint &amp; contact</a></dd>
  </dl>
</section>

@endsection
