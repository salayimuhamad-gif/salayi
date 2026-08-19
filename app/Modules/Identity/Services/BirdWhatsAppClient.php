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
 * The HTTP shape is Bird's Channels API: one POST to
 * `{base}/workspaces/{workspace}/channels/{channel}/messages`, authenticated
 * by `Authorization: AccessKey …`, carrying a template reference and one
 * named body parameter with the digits — the same template shape as the
 * owner's SDK example (slug + body components with named text parameters).
 * Everything an operator might need to adjust — endpoint, template slug,
 * parameter name — comes from `services.bird.*`, so a workspace difference is
 * a config change, not a code change.
 */
final class BirdWhatsAppClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.bird.api_key', '') !== ''
            && (string) config('services.bird.workspace_id', '') !== ''
            && (string) config('services.bird.channel_id', '') !== ''
            && (string) config('services.bird.otp_template_slug', '') !== '';
    }

    /**
     * Deliver one verification code to one number. True only when Bird
     * accepted the message.
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
                    'receiver' => [
                        'contacts' => [
                            ['identifierValue' => $toE164],
                        ],
                    ],
                    'template' => [
                        'slug' => (string) config('services.bird.otp_template_slug'),
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    [
                                        'type' => 'text',
                                        'name' => (string) config('services.bird.otp_parameter', 'code'),
                                        'text' => $code,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
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
