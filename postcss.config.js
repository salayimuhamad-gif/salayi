export default {
    plugins: {
        tailwindcss: {},
        // Sorani (ckb) and Arabic (ar) are RTL; English is LTR. Logical
        // properties are preferred in source, this is the safety net for
        // any physical property that slips through review.
        'postcss-rtlcss': {
            mode: 'combined',
            ltrPrefix: '[dir="ltr"]',
            rtlPrefix: '[dir="rtl"]',
            safeBothPrefix: '[dir]',
        },
        autoprefixer: {},
    },
};
