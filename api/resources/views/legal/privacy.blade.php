{{--
  /privacy (WS-14, product.md §1). AGENT-DRAFTED — pending owner + lawyer
  review; the reviewable draft with owner-field markers is
  docs/legal/privacy.md (keep in sync). Visible [owner review: …]
  placeholders must be filled before launch.
--}}
@extends('landing.layout')
@php($baseUrl = rtrim((string) config('app.url'), '/'))
@php($boardCss = \App\Http\Controllers\LandingController::boardCss())

@section('title', 'Privacy — Burnfront')
@section('meta-description', 'What Burnfront stores and why: guest play stays in your browser, accounts are email-only, analytics are first-party, and export and deletion are self-serve.')
@section('canonical', $baseUrl.'/privacy')

@section('content')

<section class="bf-section bf-prose">
  <p class="bf-eyebrow">Incident report · deduction puzzle</p>
  <h1>Privacy</h1>
  <p class="bf-lede">Draft pending owner and legal review.</p>

  <h2>Who is responsible</h2>
  <p>Burnfront is operated by <strong>[owner review: full legal name]</strong>, <strong>[owner review: registered address]</strong>. Privacy requests: <strong>[owner review: contact email]</strong>. The operator is the data controller under the GDPR.</p>

  <h2>What we store, and why</h2>
  <p><strong>Playing as a guest.</strong> The daily puzzle, endless mode and the Academy work without an account. Your progress, streak, provisional rating and device preferences live in your browser's local storage — strictly necessary storage, so no consent banner is required. We receive anonymized usage events (for example "a puzzle was started") tied to a random identifier, never to your name or email. Analytics are first-party only: no third-party trackers, no advertising pixels, no external fonts or CDNs.</p>
  <p><strong>With an account.</strong> Accounts protect your record; they never gate play. We store your email address (sign-in is by emailed magic link — we never store passwords), your timezone if set (used only to time streak protection alerts), your alert preference, and your solve record (times, hint usage, streak, rating).</p>
  <p><strong>Solve replays and abuse prevention.</strong> Move-by-move replays of your solves and hashed network addresses (rate limiting, anti-abuse) are kept for at most 90 days, then deleted. Raw usage events are aggregated into anonymous statistics and purged after at most 13 months.</p>
  <p><strong>Cookies.</strong> A session cookie and a CSRF token, both strictly necessary for signed-in sessions. No tracking cookies.</p>

  <h2>Email</h2>
  <p>Transactional only: sign-in links, account and security notices, deletion confirmations. Streak protection alerts are sent only if you turn them on (double opt-in), at most one per day and only on days your streak would otherwise end; every alert carries a one-click unsubscribe. No marketing email.</p>

  <h2>Your rights</h2>
  <ul class="bf-notes">
    <li><strong>Export</strong> — Settings → "Export my data (JSON)". A signed download link is emailed to you; it works once and expires after 24 hours.</li>
    <li><strong>Erasure</strong> — Settings → "Delete my account". Your profile, streak and rating are erased and your solves are anonymized; only aggregate statistics survive, with nothing linking them to you.</li>
    <li><strong>Access, rectification, objection</strong> — write to <strong>[owner review: contact email]</strong>.</li>
    <li><strong>Complaint</strong> — the Belgian Data Protection Authority (gegevensbeschermingsautoriteit.be) or your local supervisory authority.</li>
  </ul>

  <h2>Legal bases</h2>
  <p>Performance of the service (account and solve record), consent (streak protection alerts), and legitimate interest (aggregate statistics, abuse prevention).</p>

  <h2>Where data lives</h2>
  <p><strong>[owner review: hosting provider and region (EU expected) + sub-processors, including the transactional email provider]</strong>.</p>

  <h2>Changes</h2>
  <p>Material changes will be announced on this page. Last updated: <strong>[owner review: date at approval]</strong>.</p>
</section>

@endsection
