<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use Throwable;

/**
 * Builds the URL that `NotificationEnvelope` demands (spec 22.3, 31.2).
 *
 * Separate from `UnsubscribeToken` because the token is pure logic that runs
 * anywhere and this needs the router. Keeping them apart is what lets the token
 * be tested without booting the framework.
 *
 * The link is built in the RECIPIENT'S language, not the sender's. Someone who
 * reads the product in Sorani and follows an unsubscribe link into an English
 * page has been handed one more obstacle at the exact moment they are trying to
 * make the messages stop, and `LocalizedRoutes` already registers a named route
 * per locale, so honouring this costs nothing.
 */
final class UnsubscribeUrl
{
    public static function for(int $userId, string $purpose = 'alerts', ?string $locale = null): string
    {
        $token = UnsubscribeToken::issue($userId, $purpose);

        return self::fromToken($token, $locale);
    }

    public static function fromToken(string $token, ?string $locale = null): string
    {
        $locale ??= (string) config('localization.default', 'ckb');
        $default = (string) config('localization.default', 'ckb');

        // LocalizedRoutes suffixes every non-default locale's route names.
        $name = $locale === $default
            ? 'notifications.unsubscribe'
            : $locale.'.notifications.unsubscribe';

        try {
            return route($name, ['token' => $token]);
        } catch (Throwable) {
            // A locale that is registered in config but not currently enabled
            // has no route. Falling back to the default locale's link is far
            // better than throwing inside a send and losing the notification —
            // the recipient still reaches a working page.
            return route('notifications.unsubscribe', ['token' => $token]);
        }
    }
}
