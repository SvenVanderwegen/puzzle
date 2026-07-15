<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Burnfront') }}</title>
    <meta name="description" content="Burnfront — a wildfire incident-reconstruction deduction puzzle. Reconstruct the firebreaks that shaped the fire's path with pure logic.">

    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#110e0c">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Burnfront">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fonts
    @inertiaHead
</head>
<body class="bg-desk text-ash antialiased">
    @inertia
</body>
</html>
