<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The MapLibre RTL text plugin contract.
 *
 * MapLibre core ships no Arabic/Hebrew bidi or shaping engine, so without
 * the official plugin every Arabic-script map label — the basemap's own
 * names (أربيل) and the Kurdish Sorani project names (هەولێر) — renders
 * with isolated letter forms in left-to-right order. The production defect
 * this suite pins closed had exactly that shape.
 *
 * Three facts keep it closed, each checked against the artifact that
 * carries it rather than against a comment:
 *
 *   1. the plugin is an EXACT-pinned first-party dependency, present in
 *      both manifest and lock — never a runtime CDN URL;
 *   2. the SHARED adapter (the one every MapLibre surface goes through)
 *      bundles it via Vite and registers it, guarded by MapLibre's own
 *      status API, BEFORE the first Map construction;
 *   3. the built assets actually ship it, alongside the worker asset whose
 *      absence was the previous map outage.
 *
 * The behavioral half — a real Arabic-script label making the browser fetch
 * the bundled plugin from this origin — lives in tests/Browser/map-rtl.spec.ts.
 */
class MapRtlTextPluginTest extends TestCase
{
    public function test_the_rtl_plugin_is_an_exact_pinned_dependency(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);

        $this->assertArrayHasKey(
            '@mapbox/mapbox-gl-rtl-text',
            $package['dependencies'] ?? [],
            'The RTL text plugin must be a runtime dependency of the build.',
        );

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $package['dependencies']['@mapbox/mapbox-gl-rtl-text'],
            'The plugin must be exact-pinned — no semver range.',
        );

        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true);

        $this->assertArrayHasKey(
            'node_modules/@mapbox/mapbox-gl-rtl-text',
            $lock['packages'] ?? [],
            'The lock must carry the plugin with npm-written integrity data.',
        );

        $this->assertNotEmpty(
            $lock['packages']['node_modules/@mapbox/mapbox-gl-rtl-text']['integrity'] ?? '',
            'The lock entry must carry a real integrity hash.',
        );
    }

    public function test_the_shared_adapter_registers_the_plugin_before_map_construction(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/lib/map/maplibre.ts'));

        // Bundled through Vite from the pinned package — the only way the
        // asset ends up served from this origin.
        $this->assertStringContainsString(
            "from '@mapbox/mapbox-gl-rtl-text/dist/mapbox-gl-rtl-text.js?url'",
            $source,
            'The adapter must import the self-contained plugin dist through Vite (?url), not reference a URL string.',
        );

        // The dist path only resolves through the filesystem alias — its
        // absence would fail the build, but the pairing is asserted here so
        // the failure reads as a contract, not a mystery.
        $vite = (string) file_get_contents(base_path('vite.config.ts'));
        $this->assertStringContainsString(
            "'node_modules/@mapbox/mapbox-gl-rtl-text/dist/mapbox-gl-rtl-text.js'",
            $vite,
            'vite.config.ts must alias the dist path past the package exports map.',
        );

        // Guarded by MapLibre's own status API so repeated adapter
        // constructions (every Inertia navigation) never double-register.
        $this->assertStringContainsString("getRTLTextPluginStatus() === 'unavailable'", $source);
        $this->assertStringContainsString('setRTLTextPlugin(rtlTextPluginUrl, true)', $source);

        // Registration must precede the first Map construction in the
        // initialisation path.
        $register = strpos($source, 'setRTLTextPlugin(');
        $construct = strpos($source, 'new maplibre.Map(');
        $this->assertNotFalse($register);
        $this->assertNotFalse($construct);
        $this->assertLessThan(
            $construct,
            $register,
            'The RTL plugin must be configured before the first maplibre.Map is constructed.',
        );

        // No third-party CDN may sneak back in for Arabic rendering.
        $this->assertDoesNotMatchRegularExpression(
            '/unpkg\.com|jsdelivr\.net|cdnjs/i',
            $source,
            'The plugin must never be loaded from a third-party CDN.',
        );
    }

    public function test_the_built_assets_ship_the_plugin_and_the_worker(): void
    {
        $names = array_map('basename', glob(public_path('build/assets/*')) ?: []);

        $this->assertNotEmpty($names, 'public/build/assets must exist — the committed build is part of the tree.');

        $this->assertTrue(
            count(preg_grep('/^mapbox-gl-rtl-text-.*\.js$/', $names)) > 0,
            'The RTL text plugin must be emitted into the production build.',
        );

        $this->assertTrue(
            count(preg_grep('/^maplibre-gl-worker-.*\.js$/', $names)) > 0,
            'The MapLibre worker asset must still be emitted — its absence was the previous map outage.',
        );
    }
}
