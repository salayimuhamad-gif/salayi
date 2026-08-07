<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use InvalidArgumentException;

/**
 * A notification ready to send (spec 22.3).
 *
 * Spec 22.3 requires every alert to state **why it was received** and **how to
 * unsubscribe**. Both are constructor arguments rather than optional extras, so
 * an envelope without them cannot be built — the requirement is enforced by the
 * type rather than by remembering.
 *
 * That matters because the reason is the part everyone omits. A message saying
 * "a new listing matched your search" is actionable; the same message with no
 * explanation is indistinguishable from spam, and in a market where most
 * property contact arrives unsolicited on WhatsApp, being distinguishable from
 * spam is the entire proposition.
 */
final class NotificationEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $key,
        public readonly string $locale,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $reason,
        public readonly string $unsubscribeUrl,
        public readonly array $data = [],
        public readonly ?string $actionUrl = null,
        public readonly string $priority = 'normal',
        // Which ConsentGate purpose governs external delivery. Defaults to the
        // consented 'alerts'; an operational notice sets a transactional one so
        // the recipient's own moderation outcome is not gated behind marketing
        // consent.
        public readonly string $consentPurpose = 'alerts',
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A notification must state why it was received (spec 22.3).',
            );
        }

        if (trim($unsubscribeUrl) === '') {
            throw new InvalidArgumentException(
                'A notification must state how to unsubscribe (spec 22.3).',
            );
        }
    }

    /**
     * The message as a channel should render it.
     *
     * The reason and the unsubscribe line are appended by the envelope, not by
     * each channel, so a new channel cannot forget them.
     */
    public function rendered(): string
    {
        return implode("\n\n", array_filter([
            $this->body,
            '— '.$this->reason,
            $this->unsubscribeUrl,
        ]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'body' => $this->body,
            'reason' => $this->reason,
            'unsubscribe_url' => $this->unsubscribeUrl,
            'action_url' => $this->actionUrl,
            'priority' => $this->priority,
            'data' => $this->data,
        ];
    }
}
