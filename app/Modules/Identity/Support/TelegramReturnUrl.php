<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Support\Facades\Log;

/**
 * Build the "Return to MyHawler" destination that goes inside a Telegram
 * message, safely.
 *
 * A URL that leaves this server and comes back as a tappable button in a chat
 * is exactly the shape of an open redirect, so nothing about it is taken from
 * a request. The caller may choose only from a fixed set of DESTINATION KEYS;
 * the path comes from the route table, the origin comes from `APP_URL`, and
 * the result is re-checked against that origin before it is returned. There is
 * deliberately no parameter through which an arbitrary URL, host, scheme or
 * absolute path could be supplied.
 *
 * The locale is preserved because a Kurdish registrant who lands on an English
 * page has been dropped halfway through their own journey — and because the
 * button label and the destination language must agree.
 */
final class TelegramReturnUrl
{
    /**
     * The only destinations a Telegram message may point at.
     *
     * Keys, not routes, and certainly not paths: a caller can ask for
     * "wherever linking finishes" without being able to say WHERE that is.
     *
     * @var array<string, string>
     */
    private const DESTINATIONS = [
        'onboarding' => 'account.onboarding',
        'link' => 'account.telegram.link',
        'profile' => 'account.profile',
        'home' => 'home',
        // v7 BLOCKER 2: the one-time return handoff. The TOKEN is a parameter,
        // the ROUTE is not — a caller still cannot name a destination.
        'handoff' => 'telegram.return',
        // Pre-confirmation: guest-safe, authenticates nobody.
        'return_to_tab' => 'telegram.return.to_tab',
    ];

    /** @var array<int, string> */
    private const LOCALES = ['ckb', 'ar', 'en'];

    /**
     * @return string|null the absolute URL, or null if it cannot be built
     *                     safely — callers send the message without a button
     *                     rather than with a doubtful one
     */
    public static function for(string $destination, string $locale, ?string $handoffToken = null): ?string
    {
        $routeName = self::DESTINATIONS[$destination] ?? null;

        if ($routeName === null) {
            Log::channel('security')->warning('telegram.return_url_unknown_destination', [
                'destination' => $destination,
            ]);

            return null;
        }

        if (! in_array($locale, self::LOCALES, true)) {
            // Not an error worth refusing over: fall back to the default
            // language rather than dropping the button entirely.
            $locale = 'ckb';
        }

        $base = rtrim((string) config('app.url', ''), '/');

        if ($base === '') {
            Log::channel('security')->warning('telegram.return_url_no_app_url');

            return null;
        }

        $origin = parse_url($base);
        $scheme = $origin['scheme'] ?? '';
        $host = $origin['host'] ?? '';

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            Log::channel('security')->warning('telegram.return_url_bad_app_url');

            return null;
        }

        /*
         * The path is taken from the route table and then reduced to a
         * RELATIVE path. Even though localized_route() builds from APP_URL
         * already, a misconfigured URL generator (a proxy header, a stale
         * cached config) could hand back an absolute URL on another host —
         * so the host it returns is discarded rather than trusted.
         */
        if ($routeName === 'telegram.return') {
            if ($handoffToken === null || ! ctype_alnum($handoffToken) || strlen($handoffToken) < 32) {
                Log::channel('security')->warning('telegram.return_url_bad_handoff');

                return null;
            }

            $generated = localized_route($routeName, ['token' => $handoffToken], locale: $locale);
        } else {
            $generated = localized_route($routeName, locale: $locale);
        }

        $path = (string) parse_url($generated, PHP_URL_PATH);

        /*
         * An ABSENT path is the site root, not a fault.
         *
         * `parse_url('https://myhawler.com', PHP_URL_PATH)` returns null, and
         * the default locale's home route generates exactly that URL. So the
         * empty-path refusal below silently dropped the button from every
         * message pointing at the site root in the default language — the
         * message went out with no way back at all, and the only sign was a
         * `telegram.return_url_bad_path` line in the security log.
         *
         * Normalising to '/' keeps every check that follows intact: the value
         * still has to start with a single slash, and the assembled URL is
         * still re-verified against our own origin.
         */
        if ($path === '') {
            $path = '/';
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            Log::channel('security')->warning('telegram.return_url_bad_path');

            return null;
        }

        $url = $scheme.'://'.$host.($origin['port'] ?? null ? ':'.$origin['port'] : '').$path;

        // Final gate: whatever was assembled must still be on our own origin.
        $built = parse_url($url);

        if (($built['host'] ?? '') !== $host || ($built['scheme'] ?? '') !== $scheme) {
            Log::channel('security')->warning('telegram.return_url_origin_mismatch');

            return null;
        }

        return $url;
    }
}
