<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/*
 * Moved out of ProjectStatus.php.
 *
 * PSR-4 maps one class per file by name. ProjectType living inside
 * ProjectStatus.php meant `composer dump-autoload --strict-psr` reports a
 * violation, and any autoloader that trusts the mapping — an optimised
 * classmap, a static analyser, an IDE — cannot find it. It worked only
 * because something else happened to load ProjectStatus.php first, which is
 * load-order luck rather than correctness.
 */
/** Project category (spec 12.1 "project type"). */
enum ProjectType: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';
    case MixedUse = 'mixed_use';
    case Compound = 'compound';
    case Tower = 'tower';
    case Villa = 'villa';
    case Township = 'township';
    case Office = 'office';
    case Retail = 'retail';
    case Hospitality = 'hospitality';
    case Industrial = 'industrial';

    public function label(): string
    {
        return __('projects.types.'.$this->value);
    }
}
