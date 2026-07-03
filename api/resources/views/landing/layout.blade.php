{{--
  WS-15 marketing shell (ADR-0009: Blade owns /, /about, /rules).
  Meta/OG per docs/design/product.md §2; canonical is the apex origin
  (config app.url). Copy voice: calm night-shift dispatcher (COPY.md).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title')</title>
<meta name="description" content="@yield('meta-description')">
<link rel="canonical" href="@yield('canonical')">
<meta property="og:site_name" content="Burnfront">
<meta property="og:type" content="website">
<meta property="og:title" content="@yield('title')">
<meta property="og:description" content="@yield('meta-description')">
<meta property="og:url" content="@yield('canonical')">
{{-- Static placeholder path; the pipeline's pre-rendered PNGs land with WS-05. --}}
<meta property="og:image" content="{{ $baseUrl }}/og/landing.png">
<meta name="twitter:card" content="summary_large_image">
@yield('head-extra')
@include('landing.partials.critical-css')
</head>
<body>
<header class="bf-masthead">
  <a class="bf-wordmark" href="/">Burnfront</a>
  <nav aria-label="Site">
    <a href="/rules">Rules</a>
    <a href="/about">About</a>
    <a class="bf-cta" href="/daily">Play today's Burn Order</a>
  </nav>
</header>
<main>
@yield('content')
</main>
<footer class="bf-footer">
  <div class="bf-section">
    <a href="/rules">Rules</a>
    <a href="/about">About</a>
    <a href="/privacy">Privacy</a>
    <a href="/terms">Terms</a>
    <a href="/imprint">Contact</a>
  </div>
</footer>
</body>
</html>
