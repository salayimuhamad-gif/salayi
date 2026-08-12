import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // Manifest values are placeholders only. At runtime the PWA manifest
        // is served from the database by Branding (spec 9.2) so an admin can
        // change app name, icons and splash without a rebuild.
        VitePWA({
            registerType: 'prompt',
            injectRegister: null,
            manifest: false,
            workbox: {
                globPatterns: ['**/*.{js,css,woff2,svg,png}'],
                navigateFallback: '/offline',
                navigateFallbackDenylist: [/^\/admin/, /^\/install/, /^\/api/],
                runtimeCaching: [
                    {
                        urlPattern: /\/build\/assets\//,
                        handler: 'CacheFirst',
                        options: { cacheName: 'mh-assets' },
                    },
                ],
            },
        }),
    ],
    resolve: {
        // Array form: the second entry needs a REGEX find. A string key
        // matches only the bare id or id + '/', and the adapter imports the
        // file with `?url`, so a string alias never fires and resolution
        // falls through to the package exports map — which rejects the
        // path (the build error this replaces). The regex matches the
        // module id with or without a query and keeps the query intact.
        alias: [
            { find: '@', replacement: path.resolve(__dirname, 'resources/js') },
            // The RTL text plugin's exports map exposes only its package
            // root, and that root is a raw ESM wrapper importing ./icu.wasm
            // — useless as a setRTLTextPlugin() target, which must be the
            // SELF-CONTAINED worker script at dist/mapbox-gl-rtl-text.js.
            // A filesystem alias resolves the dist file directly, bypassing
            // the exports restriction without forking or patching the
            // package; the adapter imports it with ?url so the file ships
            // as a hashed asset of this origin (never a runtime CDN).
            {
                find: /^@mapbox\/mapbox-gl-rtl-text\/dist\/mapbox-gl-rtl-text\.js/,
                replacement: path.resolve(
                    __dirname,
                    'node_modules/@mapbox/mapbox-gl-rtl-text/dist/mapbox-gl-rtl-text.js',
                ),
            },
        ],
    },
    build: {
        // Hostinger shared hosting: assets are shipped pre-built inside the
        // deployment artifact, so determinism matters more than build speed.
        //
        // The filename is explicit. `manifest: true` — what this was — makes
        // Vite 5+ write public/build/.vite/manifest.json, while Laravel's Vite
        // helper looks for public/build/manifest.json. The build succeeded, the
        // assets were correct, and production would still have thrown
        // "Vite manifest not found". CI now asserts this path exists.
        manifest: 'manifest.json',
        sourcemap: false,
        chunkSizeWarningLimit: 900,
    },
});
