<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use InvalidArgumentException;

/**
 * The contact permission boundary (spec 23.3 "No marketing contact without
 * valid consent", spec 30.2 consent types, spec 22.3 alerts).
 *
 * Kept entirely separate from LeadScorer. A lead's value and a lead's
 * contactability are different questions, and the moment they share a code path
 * someone will reason that a high enough score justifies a call.
 *
 * Deny by default. Every check answers "is there a currently valid, unwithdrawn
 * consent of the right type for this channel", and anything missing, expired or
 * ambiguous is a refusal.
 */
final class ConsentGate
{
    /** Which consent type each contact purpose requires (spec 30.2). */
    private const REQUIRED_CONSENT = [
        'marketing' => 'marketing',
        'company_contact' => 'company_contact',
        'alerts' => 'alerts',
        'portfolio_contact' => 'portfolio_contact',
        'telegram_message' => 'telegram_contact_sharing',
    ];

    /**
     * A transactional message needs no marketing consent — a password reset or
     * a moderation outcome is not marketing. Listing them explicitly means the
     * exemption is a reviewed decision rather than an argument someone makes
     * later about a campaign.
     */
    private const TRANSACTIONAL_PURPOSES = ['account_security', 'moderation_outcome', 'legal_notice'];

    /**
     * @param  list<array{type: string, granted: bool, granted_at?: string|null, withdrawn_at?: string|null}>  $consents
     * @return array{allowed: bool, reason: string|null, consent_type: string|null}
     */
    public function mayContact(string $purpose, array $consents, ?string $now = null): array
    {
        if (in_array($purpose, self::TRANSACTIONAL_PURPOSES, true)) {
            return ['allowed' => true, 'reason' => 'transactional_exempt', 'consent_type' => null];
        }

        if (! array_key_exists($purpose, self::REQUIRED_CONSENT)) {
            return ['allowed' => false, 'reason' => 'unknown_contact_purpose', 'consent_type' => null];
        }

        $required = self::REQUIRED_CONSENT[$purpose];
        $current = $this->latestFor($required, $consents);

        if ($current === null) {
            return ['allowed' => false, 'reason' => 'no_consent_recorded', 'consent_type' => $required];
        }

        if (($current['granted'] ?? false) !== true) {
            return ['allowed' => false, 'reason' => 'consent_refused', 'consent_type' => $required];
        }

        $withdrawn = $current['withdrawn_at'] ?? null;

        if (is_string($withdrawn) && $withdrawn !== '' && strtotime($withdrawn) <= strtotime($now ?? 'now')) {
            return ['allowed' => false, 'reason' => 'consent_withdrawn', 'consent_type' => $required];
        }

        return ['allowed' => true, 'reason' => null, 'consent_type' => $required];
    }

    /**
     * The latest consent record of a type.
     *
     * Consent is append-only (Step 1's `consents` table), so a withdrawal is a
     * newer row rather than an edit. Taking the newest is what makes
     * "granted then withdrawn" resolve correctly; taking the first match would
     * honour a superseded grant forever.
     *
     * @param  list<array<string, mixed>>  $consents
     * @return array<string, mixed>|null
     */
    private function latestFor(string $type, array $consents): ?array
    {
        $matching = array_values(array_filter(
            $consents,
            static fn (array $c): bool => ($c['type'] ?? null) === $type,
        ));

        if ($matching === []) {
            return null;
        }

        usort($matching, static function (array $a, array $b): int {
            $at = strtotime((string) ($a['granted_at'] ?? '1970-01-01'));
            $bt = strtotime((string) ($b['granted_at'] ?? '1970-01-01'));

            return $bt <=> $at;
        });

        return $matching[0];
    }

    /**
     * Every alert must state why it was sent and how to stop it (spec 22.3).
     *
     * Returned as a structure the sender cannot omit: an alert built without
     * calling this has no `why` and no `unsubscribe_token`, and the delivery
     * layer refuses it.
     *
     * @return array{why: string, matched_rule: string, sent_at: string, unsubscribe_token: string, frequency: string}
     */
    public function alertDisclosure(
        string $matchedRule,
        string $why,
        string $unsubscribeToken,
        string $frequency = 'immediate',
        ?string $sentAt = null,
    ): array {
        if (trim($why) === '' || trim($unsubscribeToken) === '') {
            throw new InvalidArgumentException(
                'Every alert must carry a reason and an unsubscribe token (spec 22.3).',
            );
        }

        return [
            'why' => $why,
            'matched_rule' => $matchedRule,
            'sent_at' => $sentAt ?? date('c'),
            'unsubscribe_token' => $unsubscribeToken,
            'frequency' => $frequency,
        ];
    }
}
