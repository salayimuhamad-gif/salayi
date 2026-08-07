<!DOCTYPE html>
{{--
    Direction and language are resolved SERVER-side and written into the html
    element before any JavaScript runs. Setting dir from Vue after mount causes
    a visible left-to-right flash on every Sorani page load, which on a slow
    Erbil mobile connection is the first thing a user sees.

    Branding custom properties are inlined for the same reason: the palette is
    admin-editable and lives in the database, so it cannot be compiled into the
    stylesheet, and loading it asynchronously would flash the default colours.
--}}
<html
    lang="{{ config('localization.supported.'.app()->getLocale().'.html_lang', app()->getLocale()) }}"
    dir="{{ locale_direction() }}"
    class="h-full antialiased"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @if (feature('pwa'))
        {{-- Generated at request time from admin branding, so a logo or colour
             change reaches installed clients without a frontend rebuild. --}}
        <link rel="manifest" href="{{ route('pwa.manifest') }}">
        {{-- theme-color is already declared below for all pages, not only
             PWA-enabled ones; duplicating it here would let the two drift. --}}
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
    @endif
    <meta name="theme-color" content="{{ settings('branding.color_brand_hex', '#0f3e59') }}">

    <title inertia>{{ settings('branding.site_name', config('app.name')) }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    {{-- Noto Kufi Arabic is the PRIMARY face, not a fallback: it is the one
         that shapes ک / ی / ە the way a Kurdish reader expects. --}}
    <link
        href="https://fonts.bunny.net/css?family=noto-kufi-arabic:400,500,600,700|noto-sans:400,500,600"
        rel="stylesheet"
    >

    <style>
        :root {
            --mh-brand: {{ settings('branding.color_brand', '15 62 89') }};
            --mh-brand-soft: {{ settings('branding.color_brand_soft', '38 92 124') }};
            --mh-brand-strong: {{ settings('branding.color_brand_strong', '9 39 58') }};
            --mh-accent: {{ settings('branding.color_accent', '201 162 39') }};
            --mh-surface: {{ settings('branding.color_surface', '250 250 249') }};
            --mh-surface-raised: 255 255 255;
            --mh-surface-sunken: 243 243 241;
            --mh-ink: {{ settings('branding.color_ink', '23 23 23') }};
            --mh-ink-muted: 90 90 88;
            --mh-ink-faint: 140 140 137;
            --mh-line: 224 223 219;
            --mh-positive: 21 128 61;
            --mh-negative: 185 28 28;
            --mh-caution: 180 120 10;
        }

        html.dark {
            --mh-surface: 14 17 20;
            --mh-surface-raised: 22 26 30;
            --mh-surface-sunken: 9 11 13;
            --mh-ink: 240 240 238;
            --mh-ink-muted: 168 168 164;
            --mh-ink-faint: 118 118 115;
            --mh-line: 44 49 54;
        }
    </style>

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="h-full bg-surface font-sans text-ink">
    @inertia

    {{-- Loaded globally because Inertia can enter the advisor without reloading
         this root Blade view. The script is inert unless an advisor composer exists. --}}
    <script src="{{ asset('advisor-live-chat-v7.js') }}" defer></script>
</body>
</html>
