<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Enums;

/**
 * What KIND of claim a knowledge event makes (Phase 12).
 *
 * `confidence` rates how certain the author is; it cannot say what the claim
 * IS. "Prices rose 8% (statistics office)" and "we expect prices to rise" can
 * both be recorded with high confidence, and an assistant that speaks them in
 * the same voice has upgraded an expectation into a fact. This enum is the
 * machine-readable difference, and the advisory composer keys its stance
 * templates on it — confidence may hedge WITHIN a class, never promote one
 * class into another.
 *
 * Legacy rows predate the column and carry null; they are read as
 * AdminObservation (see KnowledgeEvent::effectiveEvidenceClass()), because an
 * unclassified note is at most something the team recorded — never a
 * verified fact.
 */
enum EvidenceClass: string
{
    case VerifiedFact = 'verified_fact';
    case AdminObservation = 'admin_observation';
    case MarketInterpretation = 'market_interpretation';
    case Prediction = 'prediction';

    /** A fact stated directly must have something to cite. */
    public function requiresSource(): bool
    {
        return $this === self::VerifiedFact;
    }

    public function label(): string
    {
        return __('knowledge.evidence_classes.'.$this->value);
    }
}
