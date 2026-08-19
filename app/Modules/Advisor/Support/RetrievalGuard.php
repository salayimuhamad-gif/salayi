<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Support;

/**
 * The retrieval allowlist (spec 17.3).
 *
 * Spec 17.3 does not say "prefer published data". It says "AI answers may use
 * ONLY" and then lists eleven sources. That is a closed set, so this class is a
 * deny-by-default allowlist rather than a filter: an unrecognised source type
 * is refused, and adding a new one is a deliberate edit here rather than an
 * accident of some query forgetting a `where('published')` clause.
 *
 * The rule it protects is easy to violate by omission. A draft project, an
 * unapproved price or an expired knowledge event all look exactly like their
 * published counterparts to a retrieval query written in a hurry, and the
 * resulting answer is confidently wrong in a way no reviewer would spot.
 */
final class RetrievalGuard
{
    /**
     * Source type => the state it must be in to be retrievable.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'project' => 'published',
        'area' => 'published',
        'place' => 'published',
        'price_record' => 'approved',
        'market_snapshot' => 'published',
        'knowledge_event' => 'approved',
        'company' => 'verified',
        'offer' => 'published',
        'methodology' => 'approved',
        'portfolio_property' => 'own',
        'conversation_context' => 'own',
    ];

    /** Sources scoped to the requesting user and nobody else (spec 17.5). */
    private const USER_SCOPED = ['portfolio_property', 'conversation_context'];

    /**
     * @param  array{type: string, state?: string, owner_id?: int|null, expires_at?: string|null}  $item
     * @return array{allowed: bool, reason: string|null}
     */
    public function admit(array $item, ?int $requestingUserId = null, ?string $now = null): array
    {
        // The evidence contract requires `type`; unknown values are rejected
        // by the ALLOWED lookup below, which is where untrusted input is
        // actually filtered.
        $type = $item['type'];

        if (! array_key_exists($type, self::ALLOWED)) {
            return ['allowed' => false, 'reason' => 'source_type_not_permitted'];
        }

        $required = self::ALLOWED[$type];

        if (in_array($type, self::USER_SCOPED, true)) {
            // Spec 17.5: never reveal another user's information. An absent
            // requesting user is refused rather than treated as a match.
            if ($requestingUserId === null || ($item['owner_id'] ?? null) !== $requestingUserId) {
                return ['allowed' => false, 'reason' => 'not_owned_by_requesting_user'];
            }

            return ['allowed' => true, 'reason' => null];
        }

        if (($item['state'] ?? null) !== $required) {
            return ['allowed' => false, 'reason' => 'state_not_'.$required];
        }

        // Spec 17.5: never use draft or expired knowledge.
        $expires = $item['expires_at'] ?? null;

        if (is_string($expires) && $expires !== '' && strtotime($expires) < strtotime($now ?? 'now')) {
            return ['allowed' => false, 'reason' => 'expired'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Filter a candidate set, reporting what was rejected and why.
     *
     * The rejections are returned rather than discarded because an answer built
     * on three of twelve candidates needs to say so — spec 17.5's first rule is
     * "say when data is insufficient", and that is only possible if the caller
     * knows how much was dropped.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{admitted: list<array<string, mixed>>, rejected: list<array{type: string, reason: string}>, sufficient: bool}
     */
    public function filter(array $items, ?int $requestingUserId = null, int $minimumForSufficiency = 1): array
    {
        $admitted = [];
        $rejected = [];

        foreach ($items as $item) {
            $verdict = $this->admit($item, $requestingUserId);

            if ($verdict['allowed']) {
                $admitted[] = $item;

                continue;
            }

            $rejected[] = [
                'type' => (string) ($item['type'] ?? 'unknown'),
                'reason' => (string) $verdict['reason'],
            ];
        }

        return [
            'admitted' => $admitted,
            'rejected' => $rejected,
            'sufficient' => count($admitted) >= $minimumForSufficiency,
        ];
    }

    /** @return list<string> */
    public static function permittedTypes(): array
    {
        return array_keys(self::ALLOWED);
    }
}
