import autoprefixer from 'autoprefixer';
import postcssRTLCSS from 'postcss-rtlcss';
import tailwindcss from 'tailwindcss';

/*
 * Sorani (ckb) and Arabic (ar) are RTL; English is LTR. Logical properties
 * are preferred in source; postcss-rtlcss is the safety net for any physical
 * property that slips through review.
 *
 * THE SAFETY NET IS FOR PAGE CHROME ONLY. Vite runs one postcss pipeline
 * over every stylesheet it bundles, including MapLibre's own maplibre-gl.css
 * (imported in resources/js/app.ts — a load-bearing import; see
 * frontend-guard check 2b). Left unscoped, rtlcss rewrote the vendor's
 * physical-position rules wholesale: every control-corner container gained a
 * [dir="rtl"] mirror — so the RTL corner layout was an accident of this
 * config rather than a decision anyone made — and `.maplibregl-marker`
 * gained a `right: 0` that displaced every DOM marker (the admin picker's
 * draggable pin) on an RTL page. MapLibre positions its UI with physical
 * coordinates on purpose; direction-aware corner choice belongs to the map
 * adapter (lib/map/maplibre.ts), which now places controls per document
 * direction. The wrapper below therefore skips rtlcss for MapLibre's
 * stylesheet and nothing else; map-rtl.spec.ts pins both halves (vendor CSS
 * direction-neutral, page-chrome RTL rules still generated).
 *
 * Vite resolves this config ONCE for the whole build — a per-file function
 * config cannot see which file is being processed — so the scoping has to
 * happen inside a plugin, where each Root knows its source file.
 */
const VENDOR_MAPLIBRE = /node_modules[\\/]maplibre-gl[\\/]/;

const scopedRtlcss = (options) => {
    const inner = postcssRTLCSS(options);

    return {
        postcssPlugin: 'postcss-rtlcss-scoped',
        async OnceExit(root, { postcss }) {
            const file = root.source?.input.file ?? '';

            if (VENDOR_MAPLIBRE.test(file)) {
                return;
            }

            // Nested processing applies the real plugin's full lifecycle to
            // this Root in place; later visitor plugins (autoprefixer) still
            // see the nodes it adds.
            await postcss([inner]).process(root, { from: file || undefined });
        },
    };
};

export default {
    plugins: [
        tailwindcss(),
        scopedRtlcss({
            mode: 'combined',
            ltrPrefix: '[dir="ltr"]',
            rtlPrefix: '[dir="rtl"]',
            safeBothPrefix: '[dir]',
        }),
        autoprefixer(),
    ],
};
