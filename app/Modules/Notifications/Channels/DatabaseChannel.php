<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Contracts\NotificationChannel;
use App\Modules\Notifications\Support\NotificationEnvelope;
use Illuminate\Support\Facades\DB;

/**
 * In-app delivery.
 *
 * Always available, and deliberately the fallback: a notification no external
 * transport could deliver is still recorded where the recipient will eventually
 * see it. Losing it silently because SMTP was misconfigured is the failure this
 * prevents.
 */
final class DatabaseChannel implements NotificationChannel
{
    public function key(): string
    {
        return 'database';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(array $recipient, NotificationEnvelope $envelope): array
    {
        $userId = $recipient['user_id'] ?? null;

        if ($userId === null) {
            return ['sent' => false, 'reason' => 'no_recipient_user', 'reference' => null];
        }

        $id = DB::table('notifications')->insertGetId([
            'user_id' => $userId,
            'key' => $envelope->key,
            'channel' => $this->key(),
            'locale' => $envelope->locale,
            'subject' => $envelope->subject,
            'body' => $envelope->rendered(),
            'action_url' => $envelope->actionUrl,
            'payload' => json_encode($envelope->toArray(), JSON_UNESCAPED_UNICODE),
            'priority' => $envelope->priority,
            'read_at' => null,
            // Set by the composer from the recipient's frequency preference.
            // 'pending' means the in-app record is here now and the external
            // push is waiting for the daily digest (spec 22.2).
            'digest_state' => (string) ($envelope->data['digest_state'] ?? 'none'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['sent' => true, 'reason' => null, 'reference' => (string) $id];
    }
}
