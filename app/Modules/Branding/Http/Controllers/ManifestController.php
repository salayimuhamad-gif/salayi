<?php

declare(strict_types=1);

namespace App\Modules\Branding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The PWA manifest, generated from admin branding (File one §12.1, §12.5).
 *
 * `vite.config.ts` sets `manifest: false` deliberately: a manifest baked at
 * build time cannot reflect settings an administrator changes afterwards. An
 * operator who uploads a new logo and picks a new brand colour expects the
 * installed app icon to follow, and with a static manifest it never would —
 * they would have to rebuild the frontend, which on shared hosting they cannot
 * do at all.
 *
 * So the manifest is served here, at request time, from the same settings the
 * rest of the interface reads.
 *
 * The `pwa` feature flag is honoured (§12.10) by returning 404. A site that has
 * switched off installability should not advertise a manifest the browser will
 * then act on — an already-installed app would keep updating from it.
 */
final class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (! feature('pwa')) {
            abort(404);
        }

        $name = (string) (settings('branding.site_name') ?: config('app.name', 'Mulkihawler'));
        $short = (string) (settings('branding.short_name') ?: mb_substr($name, 0, 12));

        $manifest = [
            'name' => $name,
            'short_name' => $short,
            'description' => (string) (settings('branding.description') ?: ''),
            'start_url' => '/',
            /*
             * The scope excludes nothing deliberately: /admin and /install are
             * kept out of the service worker's navigation fallback in
             * vite.config.ts instead. Narrowing scope here would also stop the
             * installed app opening an admin link somebody was legitimately
             * sent.
             */
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',

            'background_color' => (string) (settings('branding.color_surface') ?: '#ffffff'),
            'theme_color' => (string) (settings('branding.color_brand_hex') ?: '#0f3e59'),

            /*
             * dir and lang follow the site's default language rather than the
             * requesting browser. A manifest is installed once and then owned
             * by the operating system; taking the visitor's momentary language
             * would fix an Erbil deployment's home-screen label to whatever
             * language a passer-by happened to be using.
             */
            'dir' => in_array(config('app.locale'), ['ckb', 'ar'], true) ? 'rtl' : 'ltr',
            'lang' => (string) config('app.locale', 'ckb'),

            'icons' => $this->icons(),
            'shortcuts' => $this->shortcuts(),
        ];

        return response()
            ->json($manifest, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            // The correct type. Browsers accept application/json, but a
            // manifest served as JSON is one of those things that works in
            // Chrome and silently does not elsewhere.
            ->header('Content-Type', 'application/manifest+json; charset=utf-8')
            // Short cache: a branding change should reach installed clients in
            // minutes, not on next release.
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Icons from branding, falling back to nothing rather than to a placeholder.
     *
     * An operator who has not uploaded icons gets a manifest with an empty
     * icons array, which browsers treat as "not installable" — the honest
     * outcome. Shipping a default Laravel logo as somebody's app icon would be
     * worse: it installs, looks broken, and the operator has no idea where the
     * image came from.
     *
     * @return list<array<string, string>>
     */
    private function icons(): array
    {
        $icons = [];

        $sizes = [
            'branding.icon_192' => '192x192',
            'branding.icon_512' => '512x512',
        ];

        foreach ($sizes as $key => $size) {
            $path = settings($key);

            if (! is_string($path) || $path === '') {
                continue;
            }

            $icons[] = [
                'src' => $this->url($path),
                'sizes' => $size,
                'type' => 'image/png',
                'purpose' => 'any',
            ];
        }

        /*
         * A maskable icon is a separate upload, not the same file relabelled.
         * Android crops a non-maskable icon to a circle and clips whatever sits
         * near the edge — usually the wordmark.
         */
        $maskable = settings('branding.icon_maskable');

        if (is_string($maskable) && $maskable !== '') {
            $icons[] = [
                'src' => $this->url($maskable),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'maskable',
            ];
        }

        return $icons;
    }

    /**
     * Home-screen shortcuts, filtered by feature flag (§12.5).
     *
     * A shortcut into a disabled feature is worse than a missing one: it lives
     * on the operating system's launcher, survives the flag being switched off,
     * and lands the person on a 404 from outside the browser entirely.
     *
     * @return list<array<string, mixed>>
     */
    private function shortcuts(): array
    {
        $candidates = [
            ['flag' => 'market.intelligence', 'url' => '/market', 'key' => 'market'],
            ['flag' => 'map.explorer', 'url' => '/map', 'key' => 'map'],
            ['flag' => 'advisor.residential', 'url' => '/advisor', 'key' => 'advisor'],
        ];

        $shortcuts = [];

        foreach ($candidates as $candidate) {
            if (! feature($candidate['flag'])) {
                continue;
            }

            $shortcuts[] = [
                'name' => __('nav.public.'.$candidate['key']),
                'short_name' => __('nav.public.'.$candidate['key']),
                'url' => $candidate['url'],
            ];
        }

        return $shortcuts;
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }
}
