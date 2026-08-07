<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Rating provenance (spec 13.2).
 *
 * Spec 13.2 ends with four words that drive this entire enum: "These types
 * must remain separate." They are never averaged into a single number, because
 * an internal expert assessment and an anonymous public rating are different
 * kinds of claim, and blending them would let the weaker one launder itself
 * through the stronger one's credibility.
 *
 * Each type therefore carries its own aggregate, its own sample size, and its
 * own display treatment.
 */
enum RatingType: string
{
    case InternalExpert = 'internal_expert';
    case VerifiedResidentSurvey = 'verified_resident_survey';
    case CompanySubmitted = 'company_submitted';
    case PublicUser = 'public_user';
    case CalculatedMarket = 'calculated_market';
    case AiSummary = 'ai_summary';

    /**
     * Whether this type may contribute to the project's OFFICIAL score.
     *
     * Spec 13.4: "No single anonymous rating may create an official project
     * score." Public and company-submitted ratings are displayed with their
     * provenance but never feed the official figure — a developer rating their
     * own project, or one motivated anonymous user, must not move it.
     */
    public function contributesToOfficialScore(): bool
    {
        return match ($this) {
            self::InternalExpert, self::VerifiedResidentSurvey, self::CalculatedMarket => true,
            self::CompanySubmitted, self::PublicUser, self::AiSummary => false,
        };
    }

    /**
     * Minimum sample size before this type may be shown as an aggregate at all.
     *
     * One expert assessment is a legitimate published position — it is signed
     * and accountable. One anonymous public rating is noise, and showing it as
     * "1.0 / 5 from public users" is actively misleading.
     */
    public function minimumSampleSize(): int
    {
        return match ($this) {
            self::InternalExpert => 1,
            self::CalculatedMarket => 1,
            self::CompanySubmitted => 1,
            self::VerifiedResidentSurvey => 5,
            self::PublicUser => 10,
            self::AiSummary => 1,
        };
    }

    /**
     * Weight within the official score, for types that contribute at all.
     * Expressed out of 100 so the arithmetic is inspectable.
     */
    public function officialWeight(): int
    {
        return match ($this) {
            self::InternalExpert => 50,
            self::VerifiedResidentSurvey => 30,
            self::CalculatedMarket => 20,
            default => 0,
        };
    }

    /** AI output is never a source of truth (spec 17.5). */
    public function isAiGenerated(): bool
    {
        return $this === self::AiSummary;
    }

    public function requiresPublicProvenanceLabel(): bool
    {
        return true; // Spec 13.4: public ratings must always show source type.
    }

    public function label(): string
    {
        return __('projects.rating_types.'.$this->value);
    }
}
