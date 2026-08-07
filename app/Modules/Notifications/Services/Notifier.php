<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single call site for "tell this person that this happened".
 *
 * Sits on top of `NotificationDispatcher` rather than replacing it: the
 * dispatcher still owns consent and channel selection, and this owns the three
 * boring steps every caller would otherwise repeat — resolve the recipient,
 * compose the envelope, dispatch it.
 *
 * NEVER THROWS INTO THE CALLER. Same rule as `AuditLogger`, for the same
 * reason and with more at stake: the expiry sweep processes records in a loop,
 * and one seller with a malformed telegram id must not abort the sweep and
 * leave the remaining listings live. A notification that fails is a logged
 * defect; a business action rolled back because a notification failed is a
 * worse one.
 *
 * The return value says what happened so a caller that cares can assert on it.
 */
final class Notifier
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationComposer $composer,
        private readonly RecipientResolver $recipients,
        private readonly DigestPreferences $digest,
    ) {}

    /**
     * @param  array<string, string|int>  $replacements
     * @return array{delivered: list<string>, skipped: array<string, string>}
     */
    public function send(
        string $event,
        User|int|null $recipient,
        array $replacements = [],
        string $consentPurpose = 'alerts',
        ?string $actionUrl = null,
        string $priority = 'normal',
    ): array {
        try {
            $resolved = $recipient instanceof User
                ? $this->recipients->for($recipient)
                : $this->recipients->forId($recipient);

            if ($resolved === null) {
                // A record with no owner is a data problem, not a delivery
                // problem, and it is worth seeing: it means an event happened
                // that nobody was told about.
                Log::warning('Notification has no resolvable recipient', [
                    'event' => $event,
                    'recipient' => is_int($recipient) ? $recipient : null,
                ]);

                return ['delivered' => [], 'skipped' => ['*' => 'no_recipient']];
            }

            /*
             * Spec 22.2. A recipient on the daily digest still gets the in-app
             * record immediately — only the external push waits — so nothing is
             * withheld, just batched. Transactional purposes and high priority
             * bypass this entirely; see DigestPreferences for why.
             */
            $defer = $this->digest->shouldDefer(
                $this->digest->frequencyFor($resolved['user_id']),
                $consentPurpose,
                $priority,
            );

            $envelope = $this->composer->compose(
                event: $event,
                recipientId: $resolved['user_id'],
                locale: $resolved['locale'],
                replacements: $replacements,
                consentPurpose: $consentPurpose,
                actionUrl: $actionUrl,
                priority: $priority,
                digestState: $defer ? 'pending' : 'none',
            );

            // Uses the dispatcher's own `$preferred` parameter rather than
            // reaching past it: restricting the order to ['database'] is
            // exactly what that argument is for.
            return $defer
                ? $this->dispatcher->dispatch($resolved, $envelope, ['database'])
                : $this->dispatcher->dispatch($resolved, $envelope);
        } catch (Throwable $e) {
            Log::error('Notification dispatch failed', [
                'event' => $event,
                'exception' => $e->getMessage(),
            ]);

            return ['delivered' => [], 'skipped' => ['*' => 'dispatch_failed']];
        }
    }
}
