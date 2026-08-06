<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ request()->cookie('asids_theme', 'system') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- The SPA reads the CSRF token from the cookie Sanctum sets, not from a meta tag, so
         none is emitted here. Emitting one would put a token into every cached HTML
         response. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>{{ config('app.name') }}</title>

    {{-- Set before the stylesheet loads so a dark-mode user never sees a white flash. The
         class is applied from a cookie rather than from localStorage because localStorage
         is only readable after JavaScript has run, which is too late. --}}
    <script>
        (function () {
            var pref = document.documentElement.dataset.theme || 'system'
            var dark = pref === 'dark' || (pref === 'system' &&
                window.matchMedia('(prefers-color-scheme: dark)').matches)
            document.documentElement.classList.toggle('dark', dark)
        })()
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="icon" href="/favicon.ico" sizes="any">

    @vite(['resources/js/styles/app.css', 'resources/js/app/main.ts'])
</head>
<body class="bg-surface text-content antialiased">
    <div id="app"></div>

    {{-- Rendered for a client with JavaScript disabled. An accounting application cannot
         degrade gracefully to static HTML, so saying so plainly beats a blank page. --}}
    <noscript>
        <div style="padding:2rem;font-family:system-ui,sans-serif;max-width:40rem;margin:0 auto">
            <h1>JavaScript is required</h1>
            <p>{{ config('app.name') }} needs JavaScript to run. Please enable it, or use a
               different browser.</p>
        </div>
    </noscript>
</body>
</html>
