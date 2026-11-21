<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $page['props']['locale'] ?? app()->getLocale()) }}" dir="{{ in_array($page['props']['locale'] ?? app()->getLocale(), ['ar', 'he'], true) ? 'rtl' : 'ltr' }}" data-dark-allowed="{{ $storeTheme['allowsDark'] ? 'true' : 'false' }}" @class(['dark' => $storeTheme['allowsDark'] && ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (document.documentElement.dataset.darkAllowed === 'true' && appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        <meta name="theme-color" content="{{ $storeTheme['themeColor'] }}">

        <link rel="icon" href="{{ $storeTheme['favicon'] }}">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])

        {{-- After the stylesheet so the live theme overrides its defaults. Built only from validated colors and known ids, see ThemeCatalog::normalize(). --}}
        <style>{!! $storeTheme['css'] !!}html{background-color:var(--brand-surface);}</style>

        @php($seo = $page['props']['seo'] ?? null)
        @include('partials.seo', ['seo' => $seo])

        <x-inertia::head>
            @php($storeName = \App\Support\StoreIdentity::for(app()->getLocale())['name'])
            <title>{{ $seo ? $seo['title'].' - '.$storeName : $storeName }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
