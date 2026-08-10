<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Companies\Models\Company;
use App\Modules\Core\Contracts\FeatureFlagRepository;
use App\Modules\Core\Contracts\SettingsRepository;
use App\Modules\Core\Support\AdminNavigation;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Projects\Support\ActingCompanyContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shared Inertia payload.
 *
 * Spec 37.5: "Normal admin does not see system secrets." Everything here is
 * sent to the browser, so the rule is strict — only settings explicitly marked
 * public, never the raw settings table, and never anything from services.php.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Flatten the translation groups the admin shell renders.
     *
     * @return array<string, string>
     */
    private function translations(): array
    {
        $flat = [];

        $groups = ['app', 'identity', 'admin', 'nav', 'operations', 'imports', 'market', 'companies', 'marketplace', 'media', 'knowledge', 'notifications', 'home', 'geography', 'advisor', 'portfolio', 'leads', 'projects', 'advertising', 'map', 'content', 'pwa', 'system'];

        /*
         * The installer vocabulary is large and is needed on exactly one route
         * group, so it is added only there rather than shipped with every
         * admin page load. Without this the wizard rendered raw translation
         * keys — and an installer that cannot speak Sorani is the first thing
         * a Kurdish operator sees.
         */
        if (request()->is('install') || request()->is('install/*')) {
            $groups[] = 'install';
        }

        foreach ($groups as $group) {
            foreach ($this->flatten((array) trans($group), $group) as $key => $value) {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, string>
     */
    private function flatten(array $items, string $prefix): array
    {
        $flat = [];

        foreach ($items as $key => $value) {
            $full = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $full);

                continue;
            }

            $flat[$full] = (string) $value;
        }

        return $flat;
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var SettingsRepository $settings */
        $settings = app(SettingsRepository::class);
        /** @var FeatureFlagRepository $features */
        $features = app(FeatureFlagRepository::class);

        $locale = app()->getLocale();

        return array_merge(parent::share($request), [
            'auth' => [
                // Deliberately minimal. The full user object carries phone,
                // telegram id and MFA columns that must not reach a browser.
                'user' => fn (): ?array => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'locale' => $request->user()->preferred_locale,
                    'is_admin' => $request->user()->isAdministrative(),
                    /*
                     * A real signal, not a heuristic. FeatureFlags.vue used to
                     * infer "super admin" from permissions.length > 0 — true
                     * for EVERY administrative user — and mislabelled the
                     * super-admin-only toggles as usable for a System Admin.
                     * A boolean carries no secret; the roles themselves stay
                     * server-side.
                     */
                    'is_super_admin' => $request->user()->isSuperAdmin(),
                ],
                'permissions' => fn (): array => $request->user()?->allPermissions() ?? [],
            ],

            'locale' => [
                'current' => $locale,
                'direction' => locale_direction($locale),
                // The browser needs these to build a sibling URL when the user
                // switches language on a localized public page. Sharing the
                // strategy rather than reimplementing it keeps the two sides
                // from drifting, which is exactly how the hreflang defect arose.
                'default' => (string) config('localization.default', 'ckb'),
                'url_strategy' => (string) config('localization.url_strategy', 'prefix_except_default'),
                // True when the matched route declared its own locale, i.e. a
                // localized public route where the URL is authoritative.
                'route_is_localized' => ($request->route()?->defaults['locale'] ?? null) !== null,
                'available' => array_map(
                    static fn (string $code): array => [
                        'code' => $code,
                        'native' => (string) config("localization.supported.{$code}.native"),
                        'direction' => (string) config("localization.supported.{$code}.direction"),
                    ],
                    enabled_locales(),
                ),
            ],

            // Only rows flagged public AND not secret.
            'branding' => fn (): array => $settings->group('branding'),

            'features' => fn (): array => $features->all(),

            /*
             * The acting-company context, shared globally.
             *
             * Every admin screen that shows or edits project data is scoped by
             * it, so the switcher has to be reachable from all of them — not
             * only from the Wizard, which is where it lived. A user who lands
             * on the project index with two memberships otherwise sees an
             * empty list and no way to say which company they meant.
             *
             * Lazy: resolving memberships costs a query, and most requests
             * (public pages, assets) never read it.
             */
            'acting_company' => function () use ($request): ?array {
                $user = $request->user();

                if ($user === null) {
                    return null;
                }

                $available = ActingCompanyContext::available($user);

                // Shown when there is a choice to make: several companies, or
                // one company plus platform mode.
                if ($available === [] && ! ActingCompanyContext::mayUsePlatformMode($user)) {
                    return null;
                }

                $current = ActingCompanyContext::current($request);

                return [
                    'current_id' => $current,
                    'mode' => ActingCompanyContext::mode($request),
                    // Offered only to a user who may actually use it.
                    'may_use_platform' => ActingCompanyContext::mayUsePlatformMode($user),
                    'must_choose' => ActingCompanyContext::mustChoose($request),
                    'options' => Company::query()
                        ->whereIn('id', $available)
                        ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                        ->map(static fn (Company $company): array => [
                            'id' => $company->id,
                            'name' => $company->name(),
                        ])
                        ->all(),
                ];
            },

            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
                /*
                 * v6 merge: controllers across Identity flash `status`
                 * (consent-withdrawn, user-suspended, password_reset and
                 * friends) and pages read `flash.status`, but the shared
                 * props never exposed it — so every one of those alerts
                 * was unreachable in the browser while the code looked
                 * correct on both sides.
                 */
                'status' => fn (): ?string => $request->session()->get('status'),
            ],

            'app' => [
                'name' => (string) config('app.name'),
                'version' => (string) config('mulkihawler.version'),
            ],

            /*
             * Navigation is filtered SERVER-SIDE (spec 24.1). A section the
             * user cannot reach is absent from this payload entirely rather
             * than hidden with CSS — shipping it and hiding it tells a curious
             * user exactly which URLs to try.
             */
            /*
             * Map configuration. The STYLE URL only — spec 17.1 and 37.5 keep
             * provider credentials out of anything a browser receives, and a
             * Google Maps key shared here would be readable in page source.
             */
            'map' => [
                'provider' => (string) config('services.maps.provider', 'maplibre'),
                'style_url' => config('services.maps.maplibre_style_url'),
            ],

            /*
             * The unread badge (spec 22.1).
             *
             * A count, never the notifications themselves. Shipping the rows
             * into every page's shared payload would put one company's
             * moderation outcomes into the props of every screen they open,
             * and the badge only needs a number.
             *
             * Cheap: covered by the (user_id, read_at, created_at) index the
             * notifications table was created with.
             */
            'notifications' => [
                'unread' => fn (): int => $request->user() === null
                    ? 0
                    : Notification::query()->forUser((int) $request->user()->id)->unread()->count(),
            ],

            'nav' => fn (): array => $request->user() === null
                ? []
                : AdminNavigation::for($request->user(), static fn (string $flag): bool => (bool) feature($flag)),

            'navLabels' => fn (): array => (array) trans('nav'),

            // Flat translation map for the browser. Only the groups the admin
            // surface actually renders, so a page load does not ship the
            // market, advisor and marketplace vocabularies it never uses.
            'translations' => fn (): array => $this->translations(),
        ]);
    }
}
