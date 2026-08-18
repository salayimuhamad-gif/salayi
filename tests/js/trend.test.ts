/**
 * Trend marker presentation semantics — pure, node-only, no DOM.
 *
 * The table under test is the single source the adapter icons, the invest
 * badges and the homepage map all read, so these assertions ARE the marker
 * contract: up is green and upward, down is red and downward, flat is amber
 * and horizontal, and unknown is the neutral marker that claims nothing —
 * no arrow, no percentage, never rendered as flat.
 */

import {
    normaliseTrend,
    trendArrowGlyph,
    trendColour,
    trendHasClaim,
    trendIconName,
} from '../../resources/js/lib/map/trend';

let failures = 0;

function ok(name: string, condition: boolean): void {
    if (condition) {
        console.log(`  ok ${name}`);
    } else {
        failures += 1;
        console.error(`  FAIL ${name}`);
    }
}

console.log('trend icon identity');
ok('up icon', trendIconName('up') === 'trend-up');
ok('down icon', trendIconName('down') === 'trend-down');
ok('flat icon', trendIconName('flat') === 'trend-flat');
ok('unknown icon', trendIconName('unknown') === 'trend-unknown');

console.log('trend colour semantics (never the only signal — shapes differ too)');
ok('up is green', trendColour('up') === '#15803d');
ok('down is red', trendColour('down') === '#b91c1c');
ok('flat is amber', trendColour('flat') === '#b45309');
ok('unknown is neutral navy', trendColour('unknown') === '#0f3e59');
ok('all four colours are distinct', new Set([
    trendColour('up'), trendColour('down'), trendColour('flat'), trendColour('unknown'),
]).size === 4);

console.log('direction glyphs');
ok('up arrow points up', trendArrowGlyph('up') === '↑');
ok('down arrow points down', trendArrowGlyph('down') === '↓');
ok('flat is horizontal', trendArrowGlyph('flat') === '→');
ok('unknown makes NO directional claim', trendArrowGlyph('unknown') === '');

console.log('claim gating — what may carry a percentage badge');
ok('up claims', trendHasClaim('up'));
ok('down claims', trendHasClaim('down'));
ok('flat claims', trendHasClaim('flat'));
ok('unknown never claims', !trendHasClaim('unknown'));
ok('null never claims', !trendHasClaim(null));
ok('undefined never claims', !trendHasClaim(undefined));

console.log('normalisation — missing history degrades to unknown, never flat');
ok('null → unknown', normaliseTrend(null) === 'unknown');
ok('undefined → unknown', normaliseTrend(undefined) === 'unknown');
ok('garbage → unknown', normaliseTrend('sideways') === 'unknown');
ok('up passes through', normaliseTrend('up') === 'up');
ok('down passes through', normaliseTrend('down') === 'down');
ok('flat passes through only when stated', normaliseTrend('flat') === 'flat');

if (failures > 0) {
    console.error(`\n${failures} trend assertion(s) failed`);
    process.exit(1);
}

console.log('\ntrend suite: PASS');
