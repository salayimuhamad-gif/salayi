<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one seam between this platform and Bird's WhatsApp channel.
 *
 * Mirrors {@see TelegramBotResponder}'s discipline exactly: configuration
 * decides whether the channel exists at all, failures are reported as a bool
 * and logged by status only — never the phone number, never the code, and
 * never a response body that might echo either — and an unreachable provider
 * degrades to "not sent" rather than to an exception in the account flow.
 *
 * THE HTTP SHAPE IS BIRD'S OFFICIAL CHANNELS API CONTRACT, taken from the
 * current official documentation for sending template messages — NOT from the
 * TypeScript SDK's convenience surface, whose `slug`/`components` abstraction
 * does not map 1:1 onto the raw endpoint. One POST to
 *
 *     {base}/workspaces/{workspaceId}/channels/{channelId}/messages
 *     Authorization: AccessKey …
 *
 * whose body references the APPROVED TEMPLATE by its template-project id,
 * version and locale, and passes the digits as one typed parameter:
 *
 *     {
 *       "receiver": { "contacts": [ { "identifierValue": "+964…" } ] },
 *       "template": {
 *         "projectId":  "<template project UUID>",
 *         "version":    "latest",
 *         "locale":     "en",
 *         "parameters": [ { "type": "string", "key": "code", "value": "123456" } ]
 *       }
 *     }
 *
 * The documented success answer is HTTP 202 Accepted — Bird enqueues the
 * message and reports its delivery lifecycle out of band. 202 and ONLY 202 is
 * treated as sent: anything else, including an undocumented 2xx, is refused so
 * the caller revokes the minted code instead of trusting a response shape the
 * contract never promised.
 *
 * Everything an operator might need to adjust — endpoint, template project id,
 * version, locale, parameter key — comes from `services.bird.*`, so a
 * workspace difference is a config change, not a code change. The exact Bird
 * objects the owner must create, and which of these values comes from where,
 * are documented in docs/BIRD_WHATSAPP_OTP.md.
 */
final class BirdWhatsAppClient
{
    /**
     * The documented success status for message creation on the Channels API:
     * the message was ACCEPTED for delivery.
     */
    private const ACCEPTED = 202;

    public function isConfigured(): bool
    {
        return (string) config('services.bird.api_key', '') !== ''
            && (string) config('services.bird.workspace_id', '') !== ''
            && (string) config('services.bird.channel_id', '') !== ''
            && (string) config('services.bird.otp_template_project_id', '') !== '';
    }

    /**
     * Deliver one verification code to one number. True only when Bird
     * ACCEPTED the message (HTTP 202).
     */
    public function sendOtp(string $toE164, string $code): bool
    {
        if (! $this->isConfigured()) {
            Log::channel('security')->warning('bird.whatsapp_not_configured');

            return false;
        }

        $url = sprintf(
            '%s/workspaces/%s/channels/%s/messages',
            rtrim((string) config('services.bird.base_url'), '/'),
            rawurlencode((string) config('services.bird.workspace_id')),
            rawurlencode((string) config('services.bird.channel_id')),
        );

        try {
            $response = Http::timeout(8)
                ->withHeaders(['Authorization' => 'AccessKey '.config('services.bird.api_key')])
                ->asJson()
                ->post($url, [
                    /*
                     * The official receiver shape for a WhatsApp channel: the
                     * contact is identified by its E.164 number as
                     * `identifierValue`.
                     */
                    'receiver' => [
                        'contacts' => [
                            ['identifierValue' => $toE164],
                        ],
                    ],
                    'template' => [
                        'projectId' => (string) config('services.bird.otp_template_project_id'),
                        'version' => (string) config('services.bird.otp_template_version', 'latest'),
                        'locale' => (string) config('services.bird.otp_template_locale', 'en'),
                        'parameters' => [
                            [
                                'type' => 'string',
                                'key' => (string) config('services.bird.otp_parameter_key', 'code'),
                                'value' => $code,
                            ],
                        ],
                    ],
                ]);

            if ($response->status() !== self::ACCEPTED) {
                // The status is safe to record; the body may echo the number,
                // and the URL is boring — neither is logged.
                Log::channel('security')->warning('bird.whatsapp_send_failed', [
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable) {
            Log::channel('security')->warning('bird.whatsapp_unreachable');

            return false;
        }
    }
}
