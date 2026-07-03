{{--
  /terms (WS-14, product.md §1). AGENT-DRAFTED — pending owner + lawyer
  review; the reviewable draft with owner-field markers is
  docs/legal/terms.md (keep in sync). Visible [owner review: …]
  placeholders must be filled before launch.
--}}
@extends('landing.layout')
@php($baseUrl = rtrim((string) config('app.url'), '/'))
@php($boardCss = \App\Http\Controllers\LandingController::boardCss())

@section('title', 'Terms — Burnfront')
@section('meta-description', 'The terms of the Burnfront service: free daily play, optional accounts, fair-play rules for rated boards, and Belgian governing law.')
@section('canonical', $baseUrl.'/terms')

@section('content')

<section class="bf-section bf-prose">
  <p class="bf-eyebrow">Incident report · deduction puzzle</p>
  <h1>Terms of service</h1>
  <p class="bf-lede">Draft pending owner and legal review.</p>

  <h2>The service</h2>
  <p>Burnfront is a daily logic puzzle. Playing is free and does not require an account. An account protects your record (streak, rating, history) across browsers and devices. The service is provided by <strong>[owner review: legal name — see the imprint]</strong>.</p>

  <h2>Accounts</h2>
  <p>Sign-in is by emailed magic link; there are no passwords. You are responsible for access to your mailbox. You can delete your account at any time from the settings page; what deletion does is described in the <a href="/privacy">privacy policy</a>.</p>

  <h2>Fair play</h2>
  <p>Ratings, percentiles and daily ranks assume a human solver. Submitting machine-generated solutions to rated boards, tampering with solve timing, or abusing the API may make your solves ineligible for rankings and may end your account. The puzzles are free to discuss once the day's board has rolled over.</p>

  <h2>Content and intellectual property</h2>
  <p>The boards, the site, its texts and its software belong to the operator. Personal, non-commercial sharing of your results and replays is welcome. <strong>[owner review: confirm the sharing license stance]</strong></p>

  <h2>Availability and liability</h2>
  <p>The service is provided as is, without warranty of uninterrupted availability. To the extent permitted by Belgian law, the operator's liability for this free service is limited to intent and gross negligence. Nothing here limits liability that cannot lawfully be limited.</p>

  <h2>Changes</h2>
  <p>These terms may change; material changes will be announced on the site. Continued use after a change means acceptance.</p>

  <h2>Governing law</h2>
  <p>Belgian law applies. Courts of <strong>[owner review: judicial district of the registered office]</strong>. Consumers keep any mandatory protections of their country of residence.</p>

  <p>Last updated: <strong>[owner review: date at approval]</strong>.</p>
</section>

@endsection
