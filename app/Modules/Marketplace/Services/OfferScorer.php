<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Models\Offer;

/**
 * Computes the component scores OfferRanker consumes (spec 19.5).
 *
 * Step 5 built and proved the ranker — that money cannot buy an organic
 * position — and never gave it real offers. This is the half that reads an
 * offer and produces the seven merit signals.
 *
 * Nothing here is aware of sponsorship. The ranker's guarantee only holds if
 * the scores handed to it were computed without reference to who paid, so the
 * flag is not read in this file at all — not to weight, not to break a tie, not
 * to skip a penalty.
 */
final class OfferScorer
{
    /**
     * @param  array{budget_max?: string|null, property_type?: string|null, area_id?: int|null}  $preferences
     * @return array<string, int>
     */
    public function components(Offer $offer, array $preferences = []): array
    {
        return [
            'user_match' => $this->userMatch($offer, $preferences),
            'verification' => $this->verification($offer),
            'completeness' => $this->completeness($offer),
            'freshness' => $this->freshness($offer),
            'price_fit' => $this->priceFit($offer, $preferences),
            'location_fit' => $this->locationFit($offer, $preferences),
            'company_quality' => $this->companyQuality($offer),
        ];
    }

    /**
     * Completeness (spec 19.5).
     *
     * A listing missing its size, price or room count is harder to act on, and
     * ranking it alongside a complete one wastes the reader's attention. This
     * is also the only lever a seller has that is entirely within their control
     * and entirely good for the buyer — which is the right thing to reward.
     */
    private function completeness(Offer $offer): int
    {
        $fields = [
            $offer->price, $offer->size_sqm, $offer->rooms,
            $offer->description_ckb, $offer->area_id,
            $offer->latitude, $offer->source,
        ];

        $present = count(array_filter($fields, static fn ($v): bool => $v !== null && $v !== ''));

        return (int) round($present / count($fields) * 100);
    }

    /**
     * Freshness. A listing nobody has touched in three months is usually gone.
     */
    private function freshness(Offer $offer): int
    {
        $days = $offer->published_at?->diffInDays(now()) ?? 999;

        return match (true) {
            $days <= 7 => 100,
            $days <= 30 => 80,
            $days <= 60 => 55,
            $days <= 120 => 30,
            default => 10,
        };
    }

    /**
     * Verification evidence.
     *
     * A verified company's listing is more likely to be real, and this is the
     * platform's own judgement rather than the seller's claim about themselves.
     */
    private function verification(Offer $offer): int
    {
        $score = 0;

        if ($offer->company?->isVerified() === true) {
            $score += 60;
        }

        if (is_array($offer->verification_evidence) && $offer->verification_evidence !== []) {
            $score += 25;
        }

        if ($offer->project_id !== null) {
            // Attached to a known project, which is independently checkable.
            $score += 15;
        }

        return min(100, $score);
    }

    /**
     * @param  array{budget_max?: string|null, property_type?: string|null, area_id?: int|null}  $preferences
     */
    private function priceFit(Offer $offer, array $preferences): int
    {
        $budget = $preferences['budget_max'] ?? null;

        if ($budget === null || $offer->price === null) {
            return 50;
        }

        $ratio = (float) $offer->price / max(1.0, (float) $budget);

        return match (true) {
            $ratio > 1.0 => 0,
            $ratio >= 0.75 => 100,
            $ratio >= 0.5 => 75,
            default => 50,
        };
    }

    /**
     * @param  array{budget_max?: string|null, property_type?: string|null, area_id?: int|null}  $preferences
     */
    private function locationFit(Offer $offer, array $preferences): int
    {
        $areaId = $preferences['area_id'] ?? null;

        if ($areaId === null) {
            return 50;
        }

        return $offer->area_id === $areaId ? 100 : 20;
    }

    /**
     * @param  array{budget_max?: string|null, property_type?: string|null, area_id?: int|null}  $preferences
     */
    private function userMatch(Offer $offer, array $preferences): int
    {
        $wanted = $preferences['property_type'] ?? null;

        if ($wanted === null) {
            return 50;
        }

        return $offer->property_type === $wanted ? 100 : 10;
    }

    /**
     * Company quality.
     *
     * Response time, because it is the one company attribute a buyer actually
     * experiences. Deliberately NOT subscription tier: paying for a better plan
     * must not improve an organic position, which is the same rule the ranker
     * enforces one layer up.
     */
    private function companyQuality(Offer $offer): int
    {
        $minutes = $offer->company?->median_response_minutes;

        if ($minutes === null) {
            return 40;
        }

        return match (true) {
            $minutes <= 30 => 100,
            $minutes <= 120 => 80,
            $minutes <= 480 => 55,
            default => 25,
        };
    }
}
