import { defineConfig } from 'vite';
import { resolve } from 'node:path';

/*
 * Bundle the pure TypeScript suite for node.
 *
 * Vite rather than tsc because node's ESM loader requires explicit `.js`
 * extensions on relative imports, and the application source correctly does
 * not carry them — the bundler resolves them everywhere else. Compiling with
 * raw tsc would mean either changing working source to suit the test, or
 * shipping a second module-resolution convention. Vite is already a
 * devDependency, so this adds nothing to install.
 */
export default defineConfig({
    build: {
        ssr: true,
        target: 'node22',
        outDir: resolve(import.meta.dirname, '../../.tsbuild'),
        emptyOutDir: true,
        minify: false,
        rollupOptions: {
            // Both suites. A second entry rather than a glob: the list is
            // short, and an explicit one fails loudly when a file is renamed.
            input: {
                geojson: resolve(import.meta.dirname, 'geojson.test.ts'),
                geometry: resolve(import.meta.dirname, 'geometry.test.ts'),
                wizard: resolve(import.meta.dirname, 'wizard.test.ts'),
                trend: resolve(import.meta.dirname, 'trend.test.ts'),
                poi: resolve(import.meta.dirname, 'poi.test.ts'),
            },
            output: { entryFileNames: '[name].mjs', format: 'es' },
        },
    },
});
