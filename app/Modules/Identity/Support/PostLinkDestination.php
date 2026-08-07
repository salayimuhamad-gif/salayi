<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;

/**
 * Where a person goes once their Telegram link completes — decided in ONE
 * place.
 *
 * This existed in four places before, and they disagreed: the Telegram success
 * button sent people to onboarding while the poll and confirm JSON responses
 * sent them to profile. A browser test that accepted `profile|onboarding`
 * hid the disagreement rather than catching it, which is the more serious
 * fault — a test that accepts both answers cannot fail when the product picks
 * the wrong one.
 *
 * The rule, stated once:
 *
 *   - an account that has not completed onboarding goes to ONBOARDING. For a
 *     newly registered account that is always the case, and it is the approved
 *     flow: register → link → onboarding.
 *   - an account that has already completed onboarding goes to PROFILE.
 *     Somebody re-linking Telegram on an established account is not made to
 *     walk through onboarding again.
 *
 * The decision reads the real onboarding state (`onboarding_completed_at`), not
 * a guess about how new the account looks.
 */
final class PostLinkDestination
{
    /**
     * The localized absolute URL to send this user to after linking.
     */
    public static function for(User $user, ?string $locale = null): string
    {
        return localized_route(self::routeName($user), locale: $locale);
    }

    /**
     * The route NAME, exposed so tests and the return-handoff can assert the
     * destination without re-deriving the rule.
     */
    public static function routeName(User $user): string
    {
        return $user->onboarding_completed_at === null
            ? 'account.onboarding'
            : 'account.profile';
    }
}
