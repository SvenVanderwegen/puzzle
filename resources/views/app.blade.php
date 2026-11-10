<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title inertia>{{ config('app.name', 'Burnfront') }}</title>
    <meta name="description" content="Burnfront is a daily logic puzzle. A fire has burned out and you know when it reached a few squares. Work out where the firebreaks stood. One answer, no guessing.">

    {{-- Light or dark before first paint: the stored choice, else the device setting (see resources/js/lib/theme.js). --}}
    <script>
        (function () {
            var choice = null;
            try { choice = localStorage.getItem('burnfront:theme'); } catch (e) {}
            var dark = choice === 'dark' || (choice !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        })();
    </script>

    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#F1F1EE" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#121416" media="(prefers-color-scheme: dark)">
    <meta name="msapplication-TileColor" content="#CC2162">
    <link rel="icon" href="/favicon.svg?v=3" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico?v=3" sizes="any">
    <link rel="mask-icon" href="/safari-pinned-tab.svg?v=3" color="#CC2162">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=3" sizes="180x180">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Burnfront">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
