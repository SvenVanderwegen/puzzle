{{--
  GET /daily/{date} unfurl (WS-10). Crawler-facing card + a hand-off into the
  app. Spoiler-free by construction: only the pipeline's pre-rendered PNG (grid,
  spark, clues) is shown — never the board data or solution. Copy is Blade
  marketing voice (ADR-0022 exemption), calm night-shift dispatcher.
--}}
@extends('landing.layout')

@section('title', "Incident #{$incident} — Burnfront")
@section('meta-description', "A firebreak-deduction puzzle from {$weekday}'s Burn Order. One provably unique solution, no guessing. Reconstruct the breaks from when the fire arrived.")
@section('canonical', $baseUrl.'/daily/'.$date)
@section('og-image', $ogImage)

@section('content')

<section class="bf-section bf-hero" aria-labelledby="daily-heading">
  <div>
    <p class="bf-eyebrow">Incident report · {{ $tierLabel }}</p>
    <h1 id="daily-heading">Incident #{{ $incident }}</h1>
    @if ($isPast)
      <p class="bf-lede">This is {{ $weekday }}'s incident. Today's Burn Order is live — a fresh board drops every midnight UTC.</p>
    @else
      <p class="bf-lede">Today's Burn Order. Reconstruct the firebreaks from the fire's arrival times — one solution, zero guessing.</p>
    @endif
    <p class="bf-cta-row">
      <a class="bf-cta" href="/daily">{{ $isPast ? "Play today's Burn Order" : 'Contain it' }}</a>
      <a href="/rules">60-second rules</a>
    </p>
  </div>
  <figure class="bf-daily-card">
    <img src="{{ $ogImage }}" width="1200" height="630"
         alt="Incident #{{ $incident }} — a {{ $tierLabel }} board: the grid, the spark, and the numbered arrival-time clues.">
  </figure>
</section>

@endsection
