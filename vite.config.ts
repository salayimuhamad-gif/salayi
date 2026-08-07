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
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
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
