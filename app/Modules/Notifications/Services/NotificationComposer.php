<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Support\NotificationEnvelope;
use App\Modules\Notifications\Support\UnsubscribeUrl;
use RuntimeException;

/**
 * Builds an envelope for a named event (spec 22.3).
 *
 * `NotificationEnvelope` refuses to exist without a reason and an unsubscribe
 * URL. That is the right place for the rule, but it means every call site would
 * otherwise have to remember to author both — and the reason is exactly the
 * part everyone omits. So there is one composer, the reason comes from the
 * translation catalogue alongside the message, and a call site cannot supply a
 * body while forgetting to explain it.
 *
 * TRANSLATED IN THE RECIPIENT'S LANGUAGE, NOT THE ACTOR'S. A moderator working
 * in English rejecting a listing must not send an English rejection to a seller
 * who reads Sorani. `trans()` is called with an explicit locale rather than
 * relying on the request's, because the request belongs to whoever triggered
 * the event, and that is rarely the person being told.
 */
final class NotificationComposer
{
    /**
     * Which unsubscribe link a consent purpose gets.
     *
     * Transactional purposes have no consent to withdraw, but the envelope
     * still requires a URL — so they link to the "everything optional" page,
     * which states plainly that security and moderation notices keep arriving.
     * A link that quietly does nothing would be worse than the honest one.
     */
    private const UNSUBSCRIBE_PURPOSE = [
        'alerts' => 'alerts',
        'marketing' => 'marketing',
        'company_contact' => 'company_contact',
        'portfolio_contact' => 'portfolio_contact',
        'telegram_message' => 'telegram_message',
        'moderation_outcome' => 'all',
        'account_security' => 'all',
        'legal_notice' => 'all',
    ];

    /**
     * @param  array<string, string|int>  $replacements
     */
    public function compose(
        string $event,
        int $recipientId,
        string $locale,
        array $replacements = [],
        string $consentPurpose = 'alerts',
        ?string $actionUrl = null,
        string $priority = 'normal',
        string $digestState = 'none',
    ): NotificationEnvelope {
        $base = 'notifications.events.'.$event;

        return new NotificationEnvelope(
            key: $event,
            locale: $locale,
            subject: $this->line($base.'.subject', $replacements, $locale),
            body: $this->line($base.'.body', $replacements, $locale),
            reason: $this->line($base.'.reason', $replacements, $locale),
            unsubscribeUrl: UnsubscribeUrl::for(
                $recipientId,
                self::UNSUBSCRIBE_PURPOSE[$consentPurpose] ?? 'all',
                $locale,
            ),
            // `digest_state` rides on the envelope so DatabaseChannel can
            // stamp the row at insert time. The alternative — writing the row
            // and then updating it — needs the insert id, which dispatch()
            // deliberately does not return.
            data: ['event' => $event, 'digest_state' => $digestState] + $replacements,
            actionUrl: $actionUrl,
            priority: $priority,
            consentPurpose: $consentPurpose,
        );
    }

    /**
     * One translated line.
     *
     * `trans()` returns the key itself when nothing is authored (the Step 1
     * decision: a missing Sorani string must be visibly broken rather than
     * silently English). That is right for a screen and wrong for a message —
     * sending someone `notifications.events.offer_expired.subject` over
     * Telegram is worse than sending nothing. So an unresolved key throws, and
     * tests/Standalone/TranslationUsageTest.php asserts every event has all
     * three lines in all three locales so it cannot happen at run time.
     *
     * @param  array<string, string|int>  $replacements
     */
    private function line(string $key, array $replacements, string $locale): string
    {
        $value = trans($key, $replacements, $locale);

        if (! is_string($value) || $value === $key) {
            throw new RuntimeException(sprintf(
                'Notification string "%s" is not authored in "%s"; refusing to send a raw key.',
                $key,
                $locale,
            ));
        }

        return $value;
    }
}
