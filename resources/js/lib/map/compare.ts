/**
 * The three fixed compare positions (Map Phase 6): position 0/1/2 wear the
 * comparison's A/B/C identities, on the map outlines and in the panel
 * alike — ONE definition so they can never drift apart.
 *
 * Hues sit deliberately OUTSIDE the market movement palette (no green, red
 * or amber — those keep their movement meaning under a comparison) and
 * clear of the amber Phase 3 selection; each position also carries its own
 * dash pattern, because colour must never be the only distinction. This
 * module is tiny and dependency-free so the comparison panel can import it
 * without touching the lazily-loaded MapLibre chunk.
 */
export interface CompareIdentity {
    /** Stable outline/badge colour for this position. */
    colour: string;
    /** line-dasharray for the map outline — [1, 0] draws solid. */
    dash: [number, number];
    /** The visual identity letter the panel shows (Latin, like numerals). */
    letter: string;
}

export const COMPARE_IDENTITIES: readonly CompareIdentity[] = [
    { colour: '#60a5fa', dash: [1, 0], letter: 'A' },
    { colour: '#a78bfa', dash: [2.5, 1.5], letter: 'B' },
    { colour: '#22d3ee', dash: [0.8, 1.4], letter: 'C' },
];
