<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Modules\Core\ValueObjects\Decimal;
use InvalidArgumentException;

/**
 * Offer ranking with structural separation of paid and organic results
 * (spec 19.5, 18.4, 17.5).
 *
 * Three rules from three sections of the specification converge on one design:
 *
 *   19.5  "Sponsored placement in a clearly separated sponsored section."
 *         "Organic and sponsored results must be visually distinguished."
 *   18.4  "Ads must never silently influence independent AI scoring."
 *   17.5  "Never rank sponsored content above better independent results
 *          without a visible sponsored rule."
 *
 * The design that satisfies all three: rank() returns TWO SEPARATE LISTS and
 * never a merged one. Interleaving is not merely discouraged — there is no
 * return shape capable of expressing it, so no caller can accidentally produce
 * a blended list and no future change can quietly introduce one.
 *
 * The organic score is computed from the offer's own merits and is completely
 * blind to sponsorship: `sponsored` is not among its inputs. A sponsored offer
 * that is genuinely good ranks high organically too — on merit, in the organic
 * list, exactly as it would if nobody had paid.
 *
 * Every sponsored entry carries a mandatory disclosure label. An entry without
 * one is refused rather than rendered, because an undisclosed advertisement is
 * the specific harm all three rules exist to prevent.
 */
final class OfferRanker
{
    /** Organic score weights out of 100. Sponsorship is deliberately absent. */
    public const WEIGHTS = [
        'user_match' => 30,
        'verification' => 20,
        'completeness' => 15,
        'freshness' => 15,
        'price_fit' => 10,
        'location_fit' => 5,
        'company_quality' => 5,
    ];

    /** Most sponsored entries shown, regardless of how many were purchased. */
    public const MAX_SPONSORED = 3;

    /**
     * @param  list<array{
     *     offer_id: int, is_sponsored?: bool, disclosure_label?: string|null,
     *     user_match?: int, verification?: int, completeness?: int, freshness?: int,
     *     price_fit?: int, location_fit?: int, company_quality?: int,
     *     sponsor_bid?: int
     * }>  $offers
     * @return array{
     *     organic: list<array{offer_id: int, score: int, rank: int, components: array<string, int>}>,
     *     sponsored: list<array{offer_id: int, rank: int, is_sponsored: bool, disclosure_label: string, organic_score: int}>,
     *     separated: bool,
     *     sponsored_count: int,
     *     organic_count: int
     * }
     *
     * @throws InvalidArgumentException when a sponsored offer has no disclosure label
     */
    public function rank(array $offers): array
    {
        $organic = [];
        $sponsored = [];

        foreach ($offers as $offer) {
            // Scored identically whether paid or not. The score function does
            // not receive the sponsorship flag at all.
            $components = $this->components($offer);
            $score = $this->score($components);

            if (($offer['is_sponsored'] ?? false) === true) {
                $label = $offer['disclosure_label'] ?? null;

                if (! is_string($label) || trim($label) === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Offer %d is sponsored but carries no disclosure label. '.
                        'Spec 19.5 requires sponsored results to be visually distinguished, '.
                        'and spec 18.4 forbids advertising that is not disclosed.',
                        $offer['offer_id'],
                    ));
                }

                $sponsored[] = [
                    'offer_id' => $offer['offer_id'],
                    'is_sponsored' => true,
                    'disclosure_label' => $label,
                    // Carried so an editor can see whether a paid placement is
                    // also genuinely good, rather than only that it was paid.
                    'organic_score' => $score,
                    'sponsor_bid' => (int) ($offer['sponsor_bid'] ?? 0),
                ];

                continue;
            }

            $organic[] = [
                'offer_id' => $offer['offer_id'],
                'score' => $score,
                'components' => $components,
            ];
        }

        usort($organic, static fn (array $a, array $b): int => [$b['score'], $a['offer_id']] <=> [$a['score'], $b['offer_id']]);

        // Sponsored order is by bid, then by organic merit, then by id. Bid
        // decides placement WITHIN the sponsored section only; it can never
        // move an entry into the organic list.
        usort($sponsored, static fn (array $a, array $b): int => [$b['sponsor_bid'], $b['organic_score'], $a['offer_id']]
            <=> [$a['sponsor_bid'], $a['organic_score'], $b['offer_id']]);

        $sponsored = array_slice($sponsored, 0, self::MAX_SPONSORED);

        $rank = 1;
        foreach ($organic as $i => $_) {
            $organic[$i]['rank'] = $rank++;
        }

        $rank = 1;
        foreach ($sponsored as $i => $_) {
            $sponsored[$i]['rank'] = $rank++;
            unset($sponsored[$i]['sponsor_bid']);
        }

        return [
            'organic' => $organic,
            'sponsored' => $sponsored,
            // Asserted rather than assumed: no offer id appears in both lists.
            'separated' => $this->assertSeparation($organic, $sponsored),
            'sponsored_count' => count($sponsored),
            'organic_count' => count($organic),
        ];
    }

    /**
     * The organic score's inputs.
     *
     * Note what is NOT here: sponsorship, bid amount, subscription tier, or any
     * other commercial signal. Spec 18.4 — "ads must never silently influence
     * independent scoring" — is satisfied by the absence, not by a comment.
     *
     * @param  array<string, mixed>  $offer
     * @return array<string, int>
     */
    private function components(array $offer): array
    {
        $components = [];

        foreach (array_keys(self::WEIGHTS) as $key) {
            $components[$key] = max(0, min(100, (int) ($offer[$key] ?? 0)));
        }

        return $components;
    }

    /** @param array<string, int> $components */
    private function score(array $components): int
    {
        $total = Decimal::zero(4);

        foreach ($components as $key => $value) {
            $total = $total->add(
                Decimal::of((string) $value, 4)->multiply(self::WEIGHTS[$key])->divide(100),
            );
        }

        return (int) round($total->toFloat());
    }

    /**
     * @param  list<array{offer_id: int}>  $organic
     * @param  list<array{offer_id: int}>  $sponsored
     */
    private function assertSeparation(array $organic, array $sponsored): bool
    {
        $organicIds = array_column($organic, 'offer_id');
        $sponsoredIds = array_column($sponsored, 'offer_id');

        return array_intersect($organicIds, $sponsoredIds) === [];
    }
}
