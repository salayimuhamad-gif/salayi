import type { PriceTrend } from './types';

/*
 * Pure trend-presentation semantics, shared by the adapter (marker icons),
 * the invest surface and the homepage map — and unit-testable under node
 * with no DOM. One table, so the meaning of each trend value cannot drift
 * between surfaces:
 *
 *   up      → green,  upward chevron,   "price increased"
 *   down    → red,    downward chevron, "price decreased"
 *   flat    → amber,  horizontal bar,   "price stable"
 *   unknown → navy,   plain dot,        NO trend claim (never rendered as
 *                                       flat, never given a percentage)
 *
 * Colour is never the only carrier: the icon SHAPE differs per trend, and
 * the pages attach the localized accessible text.
 */

/** The adapter image name a point's trend resolves to. */
export function trendIconName(trend: PriceTrend): string {
    return `trend-${trend}`;
}

/** Marker fill per trend — shape carries the meaning alongside it. */
export function trendColour(trend: PriceTrend): string {
    switch (trend) {
        case 'up':
            return '#15803d';
        case 'down':
            return '#b91c1c';
        case 'flat':
            return '#b45309';
        default:
            return '#0f3e59';
    }
}

/** Direction as a glyph for text badges; unknown makes no claim at all. */
export function trendArrowGlyph(trend: PriceTrend): string {
    return trend === 'up' ? '↑' : trend === 'down' ? '↓' : trend === 'flat' ? '→' : '';
}

/** Whether a badge (arrow + percentage) may be rendered for this trend. */
export function trendHasClaim(trend: PriceTrend | null | undefined): trend is 'up' | 'down' | 'flat' {
    return trend === 'up' || trend === 'down' || trend === 'flat';
}

/** Server payloads may carry null from older shapes: degrade to `unknown`. */
export function normaliseTrend(value: unknown): PriceTrend {
    return value === 'up' || value === 'down' || value === 'flat' ? value : 'unknown';
}
