<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Leads\Support\ConsentGate;
use App\Modules\Notifications\Channels\DatabaseChannel;
use App\Modules\Notifications\Contracts\NotificationChannel;
use App\Modules\Notifications\Support\NotificationEnvelope;
use App\Modules\Operations\Services\AuditLogger;

/**
 * Consent-gated delivery (spec 22.3, 23.3, 30.2).
 *
 * `ConsentGate` was written in Step 6 with 35 assertions and had never gated a
 * real send. It does now, and it comes first: a recipient who has not agreed to
 * be contacted is not contacted, regardless of how important the message seems
 * to whoever queued it.
 *
 * The database channel is exempt from that gate and only that channel. An
 * in-app notice is not an unsolicited contact — the recipient has to come to
 * the product to see it — and blocking it would mean a company that declined
 * Telegram never learns its listing was rejected.
 */
final class NotificationDispatcher
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    public function __construct(
        private readonly ConsentGate $consent,
        private readonly AuditLogger $audit,
    ) {}

    public function register(NotificationChannel $channel): void
    {
        $this->channels[$channel->key()] = $channel;
    }

    /**
     * Deliver to whichever channels the recipient has consented to and that are
     * configured.
     *
     * @param  array<string, mixed>  $recipient  user_id, telegram_chat_id, consents, locale
     * @param  list<string>  $preferred
     * @return array{delivered: list<string>, skipped: array<string, string>}
     */
    public function dispatch(array $recipient, NotificationEnvelope $envelope, array $preferred = []): array
    {
        $delivered = [];
        $skipped = [];

        $order = $preferred !== [] ? $preferred : array_keys($this->channels);

        // The in-app record is always attempted, so nothing is lost when every
        // external transport is unavailable or declined.
        if (! in_array('database', $order, true)) {
            $order[] = 'database';
        }

        foreach ($order as $key) {
            $channel = $this->channels[$key] ?? null;

            if ($channel === null) {
                $skipped[$key] = 'channel_not_registered';

                continue;
            }

            if (! $channel->isAvailable()) {
                $skipped[$key] = 'channel_unavailable';

                continue;
            }

            if (! $this->mayUse($channel, $recipient, $envelope)) {
                $skipped[$key] = 'no_consent';

                continue;
            }

            $result = $channel->send($recipient, $envelope);

            $result['sent']
                ? $delivered[] = $key
                : $skipped[$key] = (string) $result['reason'];
        }

        $this->audit->record('notification.dispatched', null, [], [
            'key' => $envelope->key,
            'delivered' => $delivered,
            'skipped' => array_keys($skipped),
        ]);

        return ['delivered' => $delivered, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $recipient
     */
    private function mayUse(NotificationChannel $channel, array $recipient, NotificationEnvelope $envelope): bool
    {
        if ($channel instanceof DatabaseChannel) {
            return true;
        }

        // The purpose is carried on the envelope, drawn from ConsentGate's own
        // vocabulary — 'alerts' needs consent, while 'moderation_outcome' and
        // the other transactional purposes are exempt because requiring
        // marketing consent for a rejection notice would mean a seller never
        // learns why their property vanished (spec 30.2).
        return $this->consent->mayContact(
            $envelope->consentPurpose,
            (array) ($recipient['consents'] ?? []),
        )['allowed'];
    }
}
