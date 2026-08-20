import type { Config } from 'tailwindcss';
import defaultTheme from 'tailwindcss/defaultTheme';

export default {
    darkMode: 'class',
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.ts',
        './resources/**/*.vue',
        './app/Modules/**/Http/Controllers/**/*.php',
    ],
    theme: {
        extend: {
            // Sorani/Arabic first: Noto Kufi Arabic renders ckb correctly and
            // keeps ک / ی / ە shaped as Kurdish readers expect. The Latin face
            // is the fallback, not the primary (spec 6.1: no Western-only
            // typography decision may weaken Sorani readability).
            fontFamily: {
                sans: ['"Noto Kufi Arabic"', '"Noto Sans"', ...defaultTheme.fontFamily.sans],
                // Serif Display leads for Latin display headings and large
                // numerals; Arabic-script glyphs fall through to Kufi
                // per-glyph, so ckb/ar headings keep their correct shaping.
                display: ['"Noto Serif Display"', '"Noto Kufi Arabic"', ...defaultTheme.fontFamily.serif],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            // Populated at runtime from branding CSS custom properties so an
            // admin colour change needs no rebuild (spec 8).
            colors: {
                brand: {
                    DEFAULT: 'rgb(var(--mh-brand) / <alpha-value>)',
                    soft: 'rgb(var(--mh-brand-soft) / <alpha-value>)',
                    strong: 'rgb(var(--mh-brand-strong) / <alpha-value>)',
                },
                accent: {
                    DEFAULT: 'rgb(var(--mh-accent) / <alpha-value>)',
                    soft: 'rgb(var(--mh-accent-soft) / <alpha-value>)',
                    strong: 'rgb(var(--mh-accent-strong) / <alpha-value>)',
                },
                // The AI/data voice: insight cards, confidence meters,
                // smart-match chips. Small text on ai-soft always uses
                // ai-ink (7.4:1); plain ai on ai-soft is graphics-only.
                ai: {
                    DEFAULT: 'rgb(var(--mh-ai) / <alpha-value>)',
                    soft: 'rgb(var(--mh-ai-soft) / <alpha-value>)',
                    ink: 'rgb(var(--mh-ai-ink) / <alpha-value>)',
                },
                surface: {
                    DEFAULT: 'rgb(var(--mh-surface) / <alpha-value>)',
                    raised: 'rgb(var(--mh-surface-raised) / <alpha-value>)',
                    sunken: 'rgb(var(--mh-surface-sunken) / <alpha-value>)',
                },
                ink: {
                    DEFAULT: 'rgb(var(--mh-ink) / <alpha-value>)',
                    muted: 'rgb(var(--mh-ink-muted) / <alpha-value>)',
                    faint: 'rgb(var(--mh-ink-faint) / <alpha-value>)',
                },
                line: {
                    DEFAULT: 'rgb(var(--mh-line) / <alpha-value>)',
                    strong: 'rgb(var(--mh-line-strong) / <alpha-value>)',
                },
                positive: 'rgb(var(--mh-positive) / <alpha-value>)',
                negative: 'rgb(var(--mh-negative) / <alpha-value>)',
                caution: {
                    DEFAULT: 'rgb(var(--mh-caution) / <alpha-value>)',
                    // Large graphics only (chart dashes, status dots ≥24px,
                    // icon fills) — never text.
                    bright: 'rgb(var(--mh-caution-bright) / <alpha-value>)',
                },
            },
            // Strict 8-point spacing system (spec 6.2).
            spacing: {
                '0.5': '0.25rem',
                '18': '4.5rem',
                '22': '5.5rem',
                '30': '7.5rem',
            },
            // card/panel are the established steps; `field` (inputs, small
            // buttons) and `hero` (hero media, full-bleed panels) are ADDED
            // rather than overriding Tailwind's default sm/md/xl, so admin
            // surfaces that use the stock scale keep their exact geometry.
            borderRadius: {
                card: '0.875rem',
                panel: '1.25rem',
                field: '0.625rem',
                hero: '1.75rem',
                pill: '9999px',
                // The reference glass radius (Wave 2B): primary translucent
                // cards sit at exactly 18px, between card and panel.
                glass: '1.125rem',
            },
            // Four elevation levels, tokenized (Wave 2A): the :root defaults in
            // app.css are byte-for-byte the previous ink-based literals, so the
            // admin surfaces keep their exact shadows, while the Midnight Amber
            // public scope remaps the custom properties to its deeper, restrained
            // values. Still exactly four levels — no other shadows may exist.
            boxShadow: {
                hairline: 'var(--mh-shadow-hairline)',
                card: 'var(--mh-shadow-card)',
                raised: 'var(--mh-shadow-raised)',
                overlay: 'var(--mh-shadow-overlay)',
            },
            transitionTimingFunction: {
                calm: 'cubic-bezier(0.22, 1, 0.36, 1)',
            },
        },
    },
    plugins: [],
} satisfies Config;
