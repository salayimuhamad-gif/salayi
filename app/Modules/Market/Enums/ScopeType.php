<?php

declare(strict_types=1);

namespace App\Modules\Market\Enums;

/** What a price record or index describes (Appendix B `scope_type`). */
enum ScopeType: string
{
    case City = 'city';
    case Area = 'area';
    case Project = 'project';
    case ProjectPhase = 'project_phase';
    case UnitType = 'unit_type';

    /**
     * Coarse-to-fine ordering. A city figure may be derived from area figures,
     * never the reverse — rolling a city average down into an area would
     * invent data for that area.
     */
    public function granularity(): int
    {
        return match ($this) {
            self::City => 0,
            self::Area => 1,
            self::Project => 2,
            self::ProjectPhase => 3,
            self::UnitType => 4,
        };
    }

    public function mayRollUpTo(self $target): bool
    {
        return $target->granularity() < $this->granularity();
    }

    public function label(): string
    {
        return __('market.scope_types.'.$this->value);
    }
}
