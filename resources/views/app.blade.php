<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Burnfront') }}</title>
    <meta name="description" content="Burnfront — a wildfire incident-reconstruction deduction puzzle. Reconstruct the firebreaks that shaped the fire's path with pure logic.">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="bg-soot text-ash antialiased">
    @inertia
</body>
</html>
