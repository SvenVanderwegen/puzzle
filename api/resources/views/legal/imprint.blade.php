{{--
  /imprint (WS-14, product.md §1; Belgian requirements per critique #29:
  name, address, email, BCE/VAT if applicable). AGENT-DRAFTED — pending
  owner + lawyer review; the reviewable draft with owner-field markers is
  docs/legal/imprint.md (keep in sync). Visible [owner review: …]
  placeholders must be filled before launch.
--}}
@extends('landing.layout')
@php($baseUrl = rtrim((string) config('app.url'), '/'))
@php($boardCss = \App\Http\Controllers\LandingController::boardCss())

@section('title', 'Imprint & contact — Burnfront')
@section('meta-description', 'Who operates Burnfront: operator identity, registered address, contact email and company registration details.')
@section('canonical', $baseUrl.'/imprint')

@section('content')

<section class="bf-section bf-prose">
  <p class="bf-eyebrow">Incident report · deduction puzzle</p>
  <h1>Imprint &amp; contact</h1>
  <p class="bf-lede">Draft pending owner and legal review.</p>

  <ul class="bf-notes">
    <li><strong>Operator</strong> — <strong>[owner review: full legal name and legal form]</strong></li>
    <li><strong>Registered address</strong> — <strong>[owner review: street, number, postal code, city, Belgium]</strong></li>
    <li><strong>Email</strong> — <strong>[owner review: contact email]</strong></li>
    <li><strong>Company number (BCE/KBO)</strong> — <strong>[owner review: number, or remove if not applicable]</strong></li>
    <li><strong>VAT</strong> — <strong>[owner review: BE VAT number, or "VAT not applicable"]</strong></li>
    <li><strong>Hosting</strong> — <strong>[owner review: provider and region]</strong></li>
  </ul>
</section>

@endsection
