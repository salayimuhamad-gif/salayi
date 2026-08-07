<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale (spec 7.1).
 *
 * Order comes from config('localization.resolution_order') so the policy is
 * reviewable in one place: url, then the signed-in user's preference, then the
 * session, then Accept-Language, then the product default.
 *
 * Session sits ABOVE browser deliberately. A visitor in Erbil whose phone
 * reports `ar` but who tapped "کوردی" must stay in Sorani on the next page;
 * letting the header win would silently undo an explicit choice.
 *
 * Anything unrecognised falls back to ckb rather than to English — an unknown
 * locale is a bug, and defaulting a Sorani-first product to English hides it.
 */
final class SetLocale
{
    public const SESSION_KEY = 'app.locale';

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = enabled_locales();
        $default = (string) config('localization.default', 'ckb');

        /** @var list<string> $order */
        $order = (array) config('localization.resolution_order', ['url', 'user', 'session', 'browser', 'default']);

        $locale = null;

        foreach ($order as $strategy) {
            $candidate = match ($strategy) {
                'url' => $this->fromRoute($request) ?? $this->fromUrl($request),
                'user' => $request->user()?->preferred_locale,
                'session' => $request->session()->get(self::SESSION_KEY),
                'browser' => $this->fromBrowser($request, $enabled),
                'default' => $default,
                default => null,
            };

            if (is_string($candidate) && in_array($candidate, $enabled, true)) {
                $locale = $candidate;
                break;
            }
        }

        $locale ??= $default;

        app()->setLocale($locale);
        $request->session()->put(self::SESSION_KEY, $locale);

        /** @var array<string, mixed> $meta */
        $meta = (array) config("localization.supported.{$locale}", []);

        // Used by number/date formatting and by Carbon's translated output.
        if (isset($meta['intl']) && is_string($meta['intl'])) {
            setlocale(LC_TIME, $meta['intl']);
        }

        $response = $next($request);

        // Content-Language helps search engines pick the right hreflang
        // alternate (spec 31.2).
        $response->headers->set('Content-Language', (string) ($meta['html_lang'] ?? $locale));

        return $response;
    }

    /**
     * The locale a localized route declared for itself.
     *
     * Checked before the raw path because it is authoritative: LocalizedRoutes
     * registers each public route once per locale with this default set, so a
     * matched route already knows its language and the URL cannot be
     * overridden by a session.
     */
    private function fromRoute(Request $request): ?string
    {
        $locale = $request->route()?->defaults['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    private function fromUrl(Request $request): ?string
    {
        $segment = $request->segment(1);

        return is_string($segment) && $segment !== '' ? $segment : null;
    }

    /**
     * @param  list<string>  $enabled
     */
    private function fromBrowser(Request $request, array $enabled): ?string
    {
        $header = (string) $request->header('Accept-Language', '');

        if ($header === '') {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $tag = mb_strtolower(trim(explode(';', $part)[0]));

            if ($tag === '') {
                continue;
            }

            if (in_array($tag, $enabled, true)) {
                return $tag;
            }

            // ckb-iq -> ckb, ar-IQ -> ar, en-GB -> en
            $primary = explode('-', $tag)[0];

            if (in_array($primary, $enabled, true)) {
                return $primary;
            }

            // Some browsers still report Sorani as `ku`.
            if ($primary === 'ku' && in_array('ckb', $enabled, true)) {
                return 'ckb';
            }
        }

        return null;
    }
}
