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
         that shapes ک / ی / ە the way a Kurdish reader expects. Serif Display
         and JetBrains Mono are Latin-only usage (display headings under LTR,
         large numerals, data/coordinates), so Sorani rendering never waits on
         them — Bunny serves display=swap by default. --}}
    <link
        href="https://fonts.bunny.net/css?family=noto-kufi-arabic:400,500,600,700|noto-sans:400,500,600|noto-sans-arabic:400,500,600,700|noto-serif-display:500,600|jetbrains-mono:400,600"
        rel="stylesheet"
    >

    <style>
        :root {
            {{-- Admin-editable (DB branding) — bare RGB triples, same keys,
                 same settings() mechanism, same validation. Only the shipped
                 DEFAULTS moved to the light-first palette; operator-set values
                 still win. --}}
            --mh-brand: {{ settings('branding.color_brand', '15 62 89') }};
            --mh-brand-soft: {{ settings('branding.color_brand_soft', '38 92 124') }};
            --mh-brand-strong: {{ settings('branding.color_brand_strong', '9 39 58') }};
            --mh-accent: {{ settings('branding.color_accent', '185 142 47') }};
            --mh-surface: {{ settings('branding.color_surface', '250 249 246') }};
            --mh-ink: {{ settings('branding.color_ink', '23 24 26') }};
            {{-- Derived (hardcoded). The two new voices — champagne tints and
                 the AI teal — are default-only CSS custom properties, NOT
                 DB-backed; exposing them in Admin/Branding is out-of-scope
                 backend work. accent-strong exists because plain accent is
                 2.9:1 on ivory — graphics/hairlines only; small accent TEXT
                 always uses accent-strong (5.0:1). --}}
            --mh-accent-soft: 232 216 178;
            --mh-accent-strong: 138 101 30;
            --mh-ai: 15 118 110;
            --mh-ai-soft: 204 235 231;
            --mh-ai-ink: 11 79 74;
            --mh-surface-raised: 255 255 255;
            --mh-surface-sunken: 244 242 236;
            --mh-ink-muted: 92 94 97;
            --mh-ink-faint: 107 109 112;
            --mh-line: 226 223 214;
            --mh-line-strong: 205 201 190;
            --mh-positive: 21 128 61;
            --mh-negative: 185 28 28;
            {{-- caution darkened for AA text (5.7:1); caution-bright is the
                 old value, kept for LARGE GRAPHICS ONLY (chart dashes, status
                 dots, icon fills) — never body text. --}}
            --mh-caution: 143 91 5;
            --mh-caution-bright: 180 120 10;
            {{-- Dark compositional bands (footer, market-intelligence) —
                 consumed by .mh-dark-band, never by html.dark. --}}
            --mh-dark-surface: 24 27 30;
            --mh-dark-raised: 33 37 41;
            --mh-dark-ink: 243 243 241;
            --mh-dark-muted: 168 172 176;
            --mh-dark-line: 54 59 64;
        }

        html.dark {
            --mh-surface: 14 17 20;
            --mh-surface-raised: 22 26 30;
            --mh-surface-sunken: 9 11 13;
            --mh-ink: 240 240 238;
            --mh-ink-muted: 168 168 164;
            --mh-ink-faint: 118 118 115;
            --mh-line: 44 49 54;
            --mh-line-strong: 64 70 76;
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
    <script src="{{ asset('advisor-live-chat-v8.js') }}" defer></script>
</body>
</html>
