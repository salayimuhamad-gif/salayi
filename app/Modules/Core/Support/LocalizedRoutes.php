<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Route;

/**
 * Locale-prefixed public routing (spec 7.1 `url_strategy`, spec 31.2
 * "localized URLs").
 *
 * `config('localization.url_strategy')` has said `prefix_except_default` since
 * Step 1 and nothing honoured it. The public profile then emitted hreflang
 * alternates at `/ar/projects/…` and `/en/projects/…` while only
 * `/projects/…` was routed — so every alternate pointed at a 404, which is
 * worse than emitting none: a search engine told to index three URLs and
 * served one page and two errors will distrust all three.
 *
 * Under `prefix_except_default` the product's own language is the bare URL:
 *
 *     /projects/ankawa-sky        ckb  (default, unprefixed)
 *     /ar/projects/ankawa-sky     ar
 *     /en/projects/ankawa-sky     en
 *
 * Sorani getting the unprefixed form is the point. Relegating the default
 * language to a prefix while English sits at the root is the exact
 * afterthought-Sorani arrangement spec 7.1 exists to prevent.
 */
final class LocalizedRoutes
{
    /**
     * Register a route group once per enabled locale.
     *
     * The default locale is registered without a prefix; every other enabled
     * locale gets one. Route names are suffixed per locale so `route()` can
     * resolve a specific language, and the unprefixed default also keeps the
     * bare name so existing `route('projects.show')` calls keep working.
     */
    public static function group(callable $routes): void
    {
        $default = (string) config('localization.default', 'ckb');
        $strategy = (string) config('localization.url_strategy', 'prefix_except_default');

        foreach (enabled_locales() as $locale) {
            $isDefault = $locale === $default;
            $prefixed = ! $isDefault || $strategy === 'prefix_all';

            $group = Route::middleware('web');

            if ($prefixed) {
                $group = $group->prefix($locale)->name($locale.'.');
            }

            /*
             * The route declares its own locale as a default, and SetLocale
             * reads that before anything else. Without it, /projects/x visited
             * by someone whose session is Arabic would serve Arabic content at
             * the Sorani canonical URL — two languages under one address, which
             * is a duplicate-content problem and makes the canonical tag a lie.
             *
             * On a localized public route the URL wins absolutely. The session
             * still governs everywhere else.
             *
             * APPLIED AFTER REGISTRATION, DELIBERATELY.
             *
             * `$group->defaults(...)` was a guaranteed BadMethodCallException:
             * `defaults` is not one of RouteRegistrar's allowed attributes, so
             * every boot died before a single route loaded. Passing it as a
             * group attribute array would be worse — it parses, but nothing
             * ever reads `$action['defaults']` into `Route::$defaults`, so
             * SetLocale::fromRoute() would silently return null forever and the
             * session would quietly win at the canonical URL. The only place
             * the router honours this is `Route::defaults()` on the route
             * object itself, so the routes this group registered are diffed by
             * object identity and given the default directly.
             */
            $collection = Route::getRoutes();

            $before = [];

            foreach ($collection->getRoutes() as $existing) {
                $before[spl_object_id($existing)] = true;
            }

            $group->group(function () use ($routes): void {
                $routes();
            });

            foreach ($collection->getRoutes() as $registered) {
                if (! isset($before[spl_object_id($registered)])) {
                    $registered->defaults('locale', $locale);
                }
            }
        }
    }

    /**
     * The canonical (unprefixed, default-locale) path for a public path.
     *
     * Duplicate content is the reason this exists: `/projects/x` and
     * `/ckb/projects/x` would otherwise serve identical pages under two URLs.
     */
    public static function canonical(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * hreflang alternates that match the routes actually registered.
     *
     * Generated from the same strategy the router uses, so the two cannot
     * drift — which is precisely how the previous defect arose.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public static function alternates(string $path): array
    {
        $default = (string) config('localization.default', 'ckb');
        $strategy = (string) config('localization.url_strategy', 'prefix_except_default');
        $base = rtrim((string) config('app.url'), '/');
        $path = '/'.ltrim($path, '/');

        $alternates = [];

        foreach (enabled_locales() as $locale) {
            $prefixed = $locale !== $default || $strategy === 'prefix_all';

            $alternates[] = [
                'hreflang' => (string) config("localization.supported.{$locale}.html_lang", $locale),
                'href' => $base.($prefixed ? '/'.$locale : '').$path,
            ];
        }

        // x-default points at the product's own language, not English.
        $alternates[] = [
            'hreflang' => 'x-default',
            'href' => $base.($strategy === 'prefix_all' ? '/'.$default : '').$path,
        ];

        return $alternates;
    }

    /** The locale a request's path is asking for, or the default. */
    public static function fromPath(string $path): string
    {
        // explode() always yields at least one element, even for an empty path.
        $segment = trim(explode('/', trim($path, '/'))[0], '/');

        return in_array($segment, enabled_locales(), true)
            ? $segment
            : (string) config('localization.default', 'ckb');
    }

    /** Strip a locale prefix from a path, if present. */
    public static function stripLocale(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if ($segments !== [] && in_array($segments[0], enabled_locales(), true)) {
            array_shift($segments);
        }

        return '/'.implode('/', $segments);
    }
}
