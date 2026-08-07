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
                display: ['"Noto Kufi Arabic"', '"Noto Serif"', ...defaultTheme.fontFamily.serif],
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
                accent: 'rgb(var(--mh-accent) / <alpha-value>)',
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
                line: 'rgb(var(--mh-line) / <alpha-value>)',
                positive: 'rgb(var(--mh-positive) / <alpha-value>)',
                negative: 'rgb(var(--mh-negative) / <alpha-value>)',
                caution: 'rgb(var(--mh-caution) / <alpha-value>)',
            },
            // Strict 8-point spacing system (spec 6.2).
            spacing: {
                '0.5': '0.25rem',
                '18': '4.5rem',
                '22': '5.5rem',
                '30': '7.5rem',
            },
            borderRadius: {
                card: '0.875rem',
                panel: '1.25rem',
            },
            boxShadow: {
                card: '0 1px 2px rgb(0 0 0 / 0.04), 0 8px 24px -12px rgb(0 0 0 / 0.18)',
                raised: '0 2px 4px rgb(0 0 0 / 0.05), 0 16px 40px -18px rgb(0 0 0 / 0.24)',
            },
            transitionTimingFunction: {
                calm: 'cubic-bezier(0.22, 1, 0.36, 1)',
            },
        },
    },
    plugins: [],
} satisfies Config;
