<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\FeatureFlagRepository;
use App\Modules\Core\Contracts\SettingsRepository;
use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Localization\Support\SoraniText;
use Illuminate\Support\Facades\Route;

if (! function_exists('settings')) {
    /**
     * Read (or write) an admin-editable site setting (spec 8).
     *
     *   settings()                    -> the repository
     *   settings('site.name')         -> a value
     *   settings('site.name', 'Fall') -> a value with a fallback
     *   settings(['site.name' => 'X'])-> writes
     *
     * @param  string|array<string, mixed>|null  $key  a key to read, or a map of settings to write
     */
    function settings(string|array|null $key = null, mixed $default = null): mixed
    {
        /** @var SettingsRepository $repository */
        $repository = app(SettingsRepository::class);

        if ($key === null) {
            return $repository;
        }

        if (is_array($key)) {
            foreach ($key as $k => $value) {
                $repository->set((string) $k, $value, auth()->id());
            }

            return null;
        }

        return $repository->get($key, $default);
    }
}

if (! function_exists('feature')) {
    /**
     * Feature flag check (spec Appendix D). Unknown flags are OFF.
     *
     *   feature('map.explorer')  -> bool
     *   feature()                -> the repository
     */
    function feature(?string $flag = null): bool|FeatureFlagRepository
    {
        /** @var FeatureFlagRepository $repository */
        $repository = app(FeatureFlagRepository::class);

        return $flag === null ? $repository : $repository->enabled($flag);
    }
}

if (! function_exists('decimal')) {
    /** Exact decimal for money and market values. Never use floats here. */
    function decimal(string|int|float|Decimal $value, ?int $scale = null): Decimal
    {
        return Decimal::of($value, $scale);
    }
}

if (! function_exists('is_rtl')) {
    /** Whether the given (or current) locale renders right-to-left. */
    function is_rtl(?string $locale = null): bool
    {
        $locale ??= app()->getLocale();

        return (config("localization.supported.{$locale}.direction") ?? 'ltr') === 'rtl';
    }
}

if (! function_exists('locale_direction')) {
    function locale_direction(?string $locale = null): string
    {
        return is_rtl($locale) ? 'rtl' : 'ltr';
    }
}

if (! function_exists('enabled_locales')) {
    /**
     * Locales enabled for this deployment, with ckb guaranteed present.
     *
     * @return list<string>
     */
    function enabled_locales(): array
    {
        $immutable = (string) config('localization.immutable_default', 'ckb');
        /** @var list<string> $enabled */
        $enabled = (array) config('localization.enabled', [$immutable]);

        $supported = array_keys((array) config('localization.supported', []));
        $enabled = array_values(array_intersect($enabled, $supported));

        if (! in_array($immutable, $enabled, true)) {
            array_unshift($enabled, $immutable);
        }

        return array_values(array_unique($enabled));
    }
}

if (! function_exists('sorani_search_key')) {
    /** Fold text to its search index key (spec 7.2). Never persist over source. */
    function sorani_search_key(?string $text): string
    {
        return SoraniText::searchKey($text);
    }
}

if (! function_exists('sorani_normalize')) {
    /** Canonical cleanup, safe to store (spec 7.2). */
    function sorani_normalize(?string $text): string
    {
        return SoraniText::normalize($text);
    }
}

if (! function_exists('localized_route')) {
    /**
     * The locale-carrying twin of route() — correction §6.6.
     *
     * LocalizedRoutes registers every prefixed variant under
     * "<locale>.<name>"; the default locale keeps the bare name. A server
     * redirect built with plain route() therefore always resolves the
     * default-locale URL and silently drops the person's language on the
     * floor. This helper resolves the variant for the ACTIVE locale (or an
     * explicit one — registration knows the chosen language before the
     * session does) and falls back to the bare name for routes that were
     * never localized, so it is safe to use everywhere.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    function localized_route(string $name, array $parameters = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $default = (string) config('localization.default', 'ckb');

        if ($locale !== $default && Route::has($locale.'.'.$name)) {
            return route($locale.'.'.$name, $parameters);
        }

        return route($name, $parameters);
    }
}
