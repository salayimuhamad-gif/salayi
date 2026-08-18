<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Contracts\NotificationChannel;
use App\Modules\Notifications\Support\NotificationEnvelope;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telegram delivery (spec 22.1).
 *
 * Telegram rather than SMS because it is what Erbil actually uses, costs
 * nothing per message, and rides the mobile data a user already has. SMTP on
 * shared hosting is frequently rate-limited into uselessness.
 *
 * NEVER VERIFIED AGAINST THE REAL API. The request follows Telegram's
 * documented `sendMessage`; no call has been made. There is no bot token in
 * this environment, and inventing one to test against would prove nothing. The
 * status handling below comes from the documented contract, not an observed
 * response.
 */
final class TelegramChannel implements NotificationChannel
{
    public function key(): string
    {
        return 'telegram';
    }

    public function isAvailable(): bool
    {
        // The token lives in the environment only (spec 37.5). A missing token
        // makes the channel unavailable rather than throwing, so a deployment
        // without Telegram degrades to the database channel.
        return trim((string) config('services.telegram.bot_token')) !== '';
    }

    public function send(array $recipient, NotificationEnvelope $envelope): array
    {
        if (! $this->isAvailable()) {
            return ['sent' => false, 'reason' => 'channel_not_configured', 'reference' => null];
        }

        $chatId = $recipient['telegram_chat_id'] ?? null;

        if ($chatId === null || (string) $chatId === '') {
            return ['sent' => false, 'reason' => 'no_telegram_chat_id', 'reference' => null];
        }

        try {
            $response = Http::timeout(10)
                ->retry(2, 250)
                ->post(sprintf(
                    'https://api.telegram.org/bot%s/sendMessage',
                    (string) config('services.telegram.bot_token'),
                ), [
                    'chat_id' => $chatId,
                    // The envelope renders body, reason and unsubscribe
                    // together, so this channel cannot omit either requirement.
                    'text' => $envelope->rendered(),
                    'disable_web_page_preview' => true,
                ]);
        } catch (Throwable) {
            return ['sent' => false, 'reason' => 'transport_error', 'reference' => null];
        }

        if (! $response->successful()) {
            // 403 means the user blocked the bot. That is permanent, and
            // retrying forever would burn the queue on someone who explicitly
            // said no — which they were entitled to do.
            $reason = $response->status() === 403 ? 'recipient_blocked_bot' : 'telegram_error';

            return ['sent' => false, 'reason' => $reason, 'reference' => null];
        }

        return [
            'sent' => true,
            'reason' => null,
            'reference' => (string) ($response->json('result.message_id') ?? ''),
        ];
    }
}
