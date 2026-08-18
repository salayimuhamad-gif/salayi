<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Physical build progress (spec 12.1).
 *
 * Kept separate from DeliveryStatus on purpose: a project can be structurally
 * complete but not handed over, and in Erbil that gap is often the single most
 * important fact for a buyer. Collapsing them into one field would erase it.
 */
enum ConstructionStatus: string
{
    case Announced = 'announced';
    case Planning = 'planning';
    case Licensed = 'licensed';
    case SitePreparation = 'site_preparation';
    case Foundation = 'foundation';
    case UnderConstruction = 'under_construction';
    case Structural = 'structural_complete';
    case Finishing = 'finishing';
    case Completed = 'completed';
    case Stalled = 'stalled';
    case Cancelled = 'cancelled';

    /** Typical completion percentage, used only when none is recorded. */
    public function impliedCompletionPercent(): ?int
    {
        return match ($this) {
            self::Announced, self::Planning => 0,
            self::Licensed => 5,
            self::SitePreparation => 10,
            self::Foundation => 25,
            self::UnderConstruction => 50,
            self::Structural => 70,
            self::Finishing => 90,
            self::Completed => 100,
            // A stalled or cancelled project's percentage is whatever it
            // reached. Implying a value would be a fabrication.
            self::Stalled, self::Cancelled => null,
        };
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Completed, self::Stalled, self::Cancelled], true);
    }

    /** Statuses that warrant a visible warning on the public profile. */
    public function isAdverse(): bool
    {
        return in_array($this, [self::Stalled, self::Cancelled], true);
    }

    public function label(): string
    {
        return __('projects.construction_statuses.'.$this->value);
    }
}
