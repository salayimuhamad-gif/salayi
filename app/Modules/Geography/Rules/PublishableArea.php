<?php

declare(strict_types=1);

namespace App\Modules\Geography\Rules;

use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Services\AreaResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A manually selected area must be publishable — published, with every
 * ancestor published too.
 *
 * One rule, used by the Wizard location step, Wizard persistence, the legacy
 * ProjectRequest and every options list. Each of those previously applied a
 * different test, or none: `exists:areas,id` accepts a draft area, and a
 * published neighbourhood under an unpublished district passes a
 * publication_status check while still being undisclosable.
 *
 * Automatic resolution already applies this policy inside AreaResolver.
 * Manual selection must not be the way around it.
 */
final class PublishableArea implements ValidationRule
{
    public function __construct(private readonly ?AreaResolver $areas = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $area = Area::query()->find($value);

        if ($area === null) {
            $fail(__('projects.wizard.creation.error_area_unpublished'))->translate();

            return;
        }

        $resolver = $this->areas ?? app(AreaResolver::class);

        if (! $resolver->ancestryIsPublished($area)) {
            // Deliberately the same message for "unpublished" and "hidden by
            // an unpublished ancestor": distinguishing them would tell an
            // editor which draft areas exist above the one they picked.
            $fail(__('projects.wizard.creation.error_area_unpublished'))->translate();
        }
    }
}
