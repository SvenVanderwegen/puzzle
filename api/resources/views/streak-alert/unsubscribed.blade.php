{{--
  Landing page for the mailed streak-alert unsubscribe link (WS-21).
  Standalone document like errors/404: renders without the SPA, a session,
  or any asset pipeline, because it is reached from an email client.
--}}<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Streak protection alerts are off</title>
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
    <h1>Streak protection alerts are off.</h1>
    <p class="bf-lede">No further alert email will be sent to this account. Alerts can be turned back on from the in-game settings.</p>
    <p class="bf-cta-row">
      <a class="bf-cta" href="/">Back to the front page</a>
    </p>
  </section>
</main>
</body>
</html>
