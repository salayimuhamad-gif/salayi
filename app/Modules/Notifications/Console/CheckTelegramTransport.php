<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Channels\TelegramChannel;
use App\Modules\Notifications\Support\NotificationEnvelope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies the Telegram transport against the real API (spec 22.1, 37.5).
 *
 * The channel has been written, reviewed and never called. Every claim about
 * its behaviour — the endpoint shape, the 403-means-blocked handling — comes
 * from Telegram's documentation rather than from an observed response, and the
 * slice has said so honestly each time. This is the command that converts that
 * into an actual observation, run by whoever holds the token.
 *
 * CREDENTIALS FROM THE ENVIRONMENT ONLY (spec 37.5). The token is read through
 * `config('services.telegram.*')`, which reads `.env` and never the settings
 * table, so an administrator cannot see it and it cannot be passed as an
 * argument where it would land in shell history and the process list.
 *
 *     php artisan mulkihawler:notifications:telegram-check
 *     php artisan mulkihawler:notifications:telegram-check --chat=123456789
 *
 * Without `--chat` it performs `getMe` only, which proves the token is valid
 * and reaches nobody. `--chat` additionally sends one real message through the
 * real channel, which is the only thing that proves delivery works end to end.
 */
final class CheckTelegramTransport extends Command
{
    protected $signature = 'mulkihawler:notifications:telegram-check
                            {--chat= : Send a real test message to this chat id}
                            {--timeout=10 : Seconds to wait for the API}';

    protected $description = 'Verify the Telegram bot token and, optionally, a real send';

    public function __construct(private readonly TelegramChannel $channel)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $token = trim((string) config('services.telegram.bot_token'));

        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');
            $this->line('The channel reports itself unavailable, which is correct: a deployment');
            $this->line('without Telegram degrades to in-app delivery rather than failing.');

            return self::FAILURE;
        }

        // Never print the token. Its length and last four characters are
        // enough to tell "wrong token" from "no token" while a screenshot of
        // this output stays safe to paste into a support thread.
        $this->line(sprintf(
            'Token present: %d characters, ending %s',
            strlen($token),
            substr($token, -4),
        ));

        if (! $this->channel->isAvailable()) {
            $this->error('The channel reports unavailable despite a token being set.');

            return self::FAILURE;
        }

        $this->info('Channel reports available.');

        if (! $this->getMe($token)) {
            return self::FAILURE;
        }

        $chat = $this->option('chat');

        if ($chat === null) {
            $this->newLine();
            $this->comment('getMe succeeded. No message was sent — pass --chat=<id> to verify delivery.');

            return self::SUCCESS;
        }

        return $this->sendProbe((string) $chat) ? self::SUCCESS : self::FAILURE;
    }

    /** Confirms the token is valid without contacting anybody. */
    private function getMe(string $token): bool
    {
        try {
            $response = Http::timeout((int) $this->option('timeout'))
                ->get(sprintf('https://api.telegram.org/bot%s/getMe', $token));
        } catch (Throwable $e) {
            $this->error('Could not reach api.telegram.org: '.$e->getMessage());
            $this->line('Check outbound HTTPS egress. Shared hosting sometimes blocks it.');

            return false;
        }

        if ($response->status() === 401) {
            $this->error('401 Unauthorized — the token is not valid.');

            return false;
        }

        if (! $response->successful()) {
            $this->error(sprintf('getMe failed with HTTP %d.', $response->status()));

            return false;
        }

        $this->info(sprintf(
            'getMe OK — bot @%s (id %s)',
            (string) $response->json('result.username'),
            (string) $response->json('result.id'),
        ));

        return true;
    }

    /**
     * Sends one real message THROUGH THE CHANNEL, not around it.
     *
     * Deliberately built as a full `NotificationEnvelope` and passed to
     * `TelegramChannel::send()`. A probe that called the API directly would
     * verify Telegram and prove nothing about this product's code — the
     * envelope's rendering, the reason and unsubscribe lines it appends, and
     * the channel's own status handling are exactly what needs observing.
     */
    private function sendProbe(string $chatId): bool
    {
        $envelope = new NotificationEnvelope(
            key: 'transport_check',
            locale: (string) config('localization.default', 'ckb'),
            subject: 'Mulkihawler transport check',
            body: 'This is a delivery test sent by mulkihawler:notifications:telegram-check.',
            reason: 'You received this because someone ran a transport check against this chat id.',
            unsubscribeUrl: rtrim((string) config('app.url'), '/').'/unsubscribe/transport-check',
            consentPurpose: 'account_security',
        );

        $result = $this->channel->send(['telegram_chat_id' => $chatId], $envelope);

        if ($result['sent'] === true) {
            $this->info(sprintf('Message delivered. Telegram message id: %s', (string) $result['reference']));
            $this->line('The rendered message included the reason and unsubscribe lines (spec 22.3).');

            return true;
        }

        $this->error(sprintf('Send refused: %s', (string) $result['reason']));

        match ($result['reason']) {
            'recipient_blocked_bot' => $this->line(
                'Telegram returned 403. That chat has blocked the bot, or has never started it — '
                .'a user must message the bot once before it may message them.',
            ),
            'no_telegram_chat_id' => $this->line('No chat id was supplied.'),
            'transport_error' => $this->line('The request threw before a response arrived. Check egress and DNS.'),
            default => $this->line('Telegram returned a non-success status. See the response above.'),
        };

        return false;
    }
}
