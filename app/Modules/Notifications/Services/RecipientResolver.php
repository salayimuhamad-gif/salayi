<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\User;

/**
 * Turns a user into the recipient array `NotificationDispatcher` expects.
 *
 * The dispatcher deliberately takes an array rather than a model, so it can be
 * driven from a queued job that no longer holds one. Something still has to
 * build that array correctly, and getting it wrong is quiet: an empty
 * `consents` key makes `ConsentGate` deny by default, so a mistake here shows
 * up as notifications that simply never arrive rather than as an error.
 *
 * Consents are read as ALL rows of every type, not the current state. The gate
 * resolves "granted then withdrawn" itself by taking the newest of each type,
 * and pre-filtering here to "granted only" would hide the withdrawal from it
 * and re-enable contact someone had refused.
 */
final class RecipientResolver
{
    /**
     * @return array{user_id: int, telegram_chat_id: string|null, locale: string, consents: list<array<string, mixed>>}
     */
    public function for(User $user): array
    {
        return [
            'user_id' => (int) $user->id,
            // `telegram_id` is in the model's $hidden precisely so it cannot
            // reach a template or an API resource (spec 32.2). Reading it
            // explicitly here is the sanctioned path: it goes to a transport,
            // never into a response.
            'telegram_chat_id' => $this->telegramChatId($user),
            'locale' => (string) ($user->preferred_locale ?: config('localization.default', 'ckb')),
            'consents' => $this->consents($user),
        ];
    }

    /** @return array{user_id: int, telegram_chat_id: string|null, locale: string, consents: list<array<string, mixed>>}|null */
    public function forId(?int $userId): ?array
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user === null ? null : $this->for($user);
    }

    private function telegramChatId(User $user): ?string
    {
        $raw = $user->getAttribute('telegram_id');

        return $raw === null || (string) $raw === '' ? null : (string) $raw;
    }

    /** @return list<array<string, mixed>> */
    private function consents(User $user): array
    {
        return Consent::query()
            ->where('user_id', $user->id)
            ->get()
            ->map(static fn (Consent $c): array => [
                'type' => (string) $c->type,
                'granted' => (bool) $c->granted,
                'granted_at' => $c->granted_at?->toIso8601String(),
                'withdrawn_at' => $c->withdrawn_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
