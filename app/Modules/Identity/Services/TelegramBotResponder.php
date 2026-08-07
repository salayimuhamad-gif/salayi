<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Support\TelegramReturnUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The bot's side of the login conversation (File one §7.3).
 *
 * Phase 8 built everything that RECEIVES a shared contact and nothing that ASKS
 * for one, which left the flow with no entry point: a person could open the
 * deep link, land in an empty chat, and have no button to press.
 *
 * The keyboard is the whole point. §7.3 requires Telegram's official Share
 * Contact button specifically, because it is the only control that makes
 * Telegram attach `contact.user_id` — which is in turn the only thing that
 * distinguishes a proven number from a typed one (§7.4). A bot that asked
 * "please send your number" as text would collect strings that look identical
 * in the database and mean nothing.
 *
 * `one_time_keyboard` and `resize_keyboard` are set because this replaces the
 * person's normal keyboard: without them the button persists after use and the
 * chat becomes unusable for ordinary typing.
 */
final class TelegramBotResponder
{
    private const API = 'https://api.telegram.org/bot';

    public function isConfigured(): bool
    {
        return (string) config('services.telegram.bot_token', '') !== '';
    }

    /**
     * Reply to /start <token> with the Share Contact button.
     *
     * The token is echoed in the message body, not in the button. Telegram's
     * contact button carries no payload of its own, so the intent has to be
     * recoverable from the conversation — the webhook reads it back out of the
     * reply's `reply_to_message`.
     */
    public function requestContact(int|string $chatId, string $token, string $locale = 'ckb'): bool
    {
        return $this->send('sendMessage', [
            'chat_id' => $chatId,
            'text' => __('identity.telegram.bot_prompt', [], $locale)."\n\n".$token,
            'reply_markup' => json_encode([
                'keyboard' => [[[
                    'text' => __('identity.telegram.bot_share_button', [], $locale),
                    // The official control. Nothing else produces a verified
                    // contact payload.
                    'request_contact' => true,
                ]]],
                'one_time_keyboard' => true,
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** Confirm a successful sign-in and restore the normal keyboard. */
    public function confirmSignIn(int|string $chatId, string $locale = 'ckb'): bool
    {
        return $this->send('sendMessage', [
            'chat_id' => $chatId,
            'text' => __('identity.telegram.bot_verified', [], $locale),
            // Removing the keyboard matters: leaving a contact button on screen
            // after verification invites a second, pointless share.
            'reply_markup' => json_encode(['remove_keyboard' => true], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Tell the person plainly that the attempt failed.
     *
     * The reason is never included. A caller who guessed a token should not
     * learn whether it was unknown, expired or already spent — and the person
     * who legitimately took too long only needs to know to start again.
     */
    /**
     * Registration completed: the person is standing in Telegram, and the
     * browser tab is what finishes signing them in — so the message says both
     * things, in their language, and removes any leftover keyboard.
     */
    public function confirmRegistration(int|string $chatId, string $locale = 'ckb'): bool
    {
        return $this->send('sendMessage', [
            'chat_id' => $chatId,
            'text' => __('identity.telegram.bot_registered', [], $locale),
            'reply_markup' => json_encode(['remove_keyboard' => true], JSON_THROW_ON_ERROR),
        ]);
    }

    public function reportFailure(int|string $chatId, string $locale = 'ckb'): bool
    {
        return $this->send('sendMessage', [
            'chat_id' => $chatId,
            'text' => __('identity.telegram.bot_failed', [], $locale),
            'reply_markup' => json_encode(['remove_keyboard' => true], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    /**
     * C1: the account-link Start succeeded — say so in the chat.
     */
    public function confirmLinked(int|string $chatId, string $locale = 'ckb', ?string $handoffToken = null): bool
    {
        return $this->send('sendMessage', $this->withReturnButton([
            'chat_id' => $chatId,
            'text' => __('identity.telegram.bot_linked', [], $locale),
        ], $locale, $handoffToken === null ? 'link' : 'handoff', $handoffToken));
    }

    /**
     * Attach the localized "Return to MyHawler" button.
     *
     * An inline URL button rather than a Web App button: it needs no bot
     * domain configuration, works in every Telegram client, and opens the
     * ordinary session-bearing browser — which is the point, because the
     * person is already signed in there and the tab they left is polling.
     *
     * If the URL cannot be built safely the message goes out WITHOUT a
     * button. A chat message with no button is a small inconvenience; a
     * button pointing somewhere unverified is an open redirect delivered by
     * our own bot.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withReturnButton(array $payload, string $locale, string $destination, ?string $handoffToken = null): array
    {
        $url = TelegramReturnUrl::for($destination, $locale, $handoffToken);

        if ($url === null) {
            return $payload;
        }

        if (app()->environment(['testing', 'local'])) {
            /*
             * Browser-test introspection only, and it records the URL that was
             * just sent to the chat — which the person holding that chat
             * already has. Keyed by destination so a test can ask for the
             * PRE-confirmation button specifically, which is the one that must
             * never authenticate anybody.
             */
            Cache::put(
                'testing:last-telegram-button:'.$destination,
                ['url' => $url, 'text' => __('identity.telegram.return_button', [], $locale)],
                300,
            );
        }

        $payload['reply_markup'] = json_encode([
            'inline_keyboard' => [[
                [
                    'text' => __('identity.telegram.return_button', [], $locale),
                    'url' => $url,
                ],
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $payload;
    }

    /**
     * v4 §5: the Start was accepted but the link is NOT done — the person
     * has to confirm it in the browser they started from.
     *
     * Saying this in the chat matters more than it looks. Without it the
     * bot goes silent after Start, the person assumes it worked, and the
     * confirmation sitting in the other tab is never pressed. It also
     * quietly does security work: somebody who pressed Start on a link
     * that is not theirs is told, in plain language, that the account
     * owner now has to approve it.
     */
    public function requestBrowserConfirmation(int|string $chatId, string $locale = 'ckb'): bool
    {
        /*
         * Points at the guest-safe "go back to your tab" page, NOT the
         * authenticated linking page.
         *
         * Telegram opens this in an in-app browser with none of this site's
         * cookies, and at this moment no handoff exists — the person has not
         * confirmed the candidate yet, and authenticating a cold browser
         * before they do would hand the anti-hijack gate to whoever holds the
         * link. The old button therefore led a cold browser to the login
         * screen while the message implied it was the way back.
         *
         * The secure one-time handoff button comes AFTER confirmation, from
         * confirmLinked().
         */
        return $this->send('sendMessage', $this->withReturnButton([
            'chat_id' => $chatId,
            'text' => __('identity.telegram.bot_confirm_in_browser', [], $locale),
        ], $locale, 'return_to_tab'));
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $method, array $payload): bool
    {
        if (! $this->isConfigured()) {
            Log::channel('security')->warning('telegram.bot_token_not_configured', ['method' => $method]);

            return false;
        }

        try {
            $response = Http::timeout(8)
                ->asJson()
                ->post(self::API.config('services.telegram.bot_token').'/'.$method, $payload);

            if (! $response->successful()) {
                // The status is safe to record; the body may echo the message,
                // and the URL carries the bot token, so neither is logged.
                Log::channel('security')->warning('telegram.send_failed', [
                    'method' => $method,
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable) {
            Log::channel('security')->warning('telegram.send_unreachable', ['method' => $method]);

            return false;
        }
    }
}
