<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * One definition of "the same phone number".
 *
 * `users.phone_index` is a keyed HMAC of the normalised number, and equality is
 * the only operation it supports. So two pieces of code that normalise the same
 * number differently do not produce a near miss — they produce two accounts
 * that can never find each other, and a person who registers with
 * `0750 123 4567` cannot sign in with `+964 750 123 4567`.
 *
 * That is not hypothetical: the normalisation lived in three private methods
 * before this class, and they did NOT agree. `RegistrationController::toE164()`
 * added the country code when it was missing; the two service-level
 * `normalise()` methods did not, and simply prefixed `+` to whatever digits
 * remained. They happened to produce the same result only because the
 * controller always ran first and handed them a number that was already
 * complete. The moment a second entry point appeared — signing in by phone —
 * that coincidence stopped holding.
 *
 * The canonical form is E.164 for Iraq: `+964` followed by the subscriber
 * number, with the trunk `0` removed.
 *
 * The service-level methods are deliberately left where they are. They receive
 * values that are already canonical (the controller's output) or that come from
 * Telegram's contact payload, and rewriting the normalisation underneath a
 * blind index that is already populated is how existing accounts get lost.
 * New code calls this.
 */
final class PhoneNumber
{
    /**
     * Canonical E.164, from any of the ways an Iraqi number gets written.
     *
     *   0750 123 4567   →  +9647501234567
     *   7501234567      →  +9647501234567
     *   00964750123456  →  +964750123456
     *   +964 750 123 4567 → +9647501234567
     *
     * Non-digits are discarded rather than rejected: spaces, dashes and
     * parentheses are how people write numbers down, not part of the number.
     * An input with no digits at all returns `+964`, which matches no account —
     * the caller's failure path handles it exactly like any other wrong
     * credential, which is what keeps this from becoming a validity oracle.
     */
    public static function toE164(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        // The trunk prefix. Iraqi mobile numbers are written 07XX locally and
        // 9647XX internationally; the leading zero belongs to the former only.
        $digits = ltrim($digits, '0');

        if (! str_starts_with($digits, '964')) {
            $digits = '964'.$digits;
        }

        return '+'.$digits;
    }
}
