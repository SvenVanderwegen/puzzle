{{--
  Custom 404 (WS-15, critique #26). Covers, among everything else, future
  daily dates — /daily/{date} beyond the calendar has no page (WS-07 404s
  the API; this is the web face of the same rule). Standalone document:
  the error path must not depend on a controller round-trip.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>404 — no incident at this address</title>
<meta name="robots" content="noindex">
@include('landing.partials.critical-css', ['boardCss' => \App\Http\Controllers\LandingController::boardCss()])
</head>
<body>
<header class="bf-masthead">
  <a class="bf-wordmark" href="/">Burnfront</a>
</header>
<main>
  <section class="bf-section bf-prose">
    <p class="bf-eyebrow">Incident report · deduction puzzle</p>
    <h1>No incident at this address.</h1>
    <p class="bf-lede">The page you asked for is not on the map. If you followed a dated link, that incident may not have been dispatched yet — new ones drop at midnight UTC.</p>
    <p class="bf-cta-row">
      <a class="bf-cta" href="/daily">Play today's Burn Order</a>
      <a href="/">Back to the front page</a>
    </p>
  </section>
</main>
</body>
</html>
