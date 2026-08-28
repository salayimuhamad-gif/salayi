<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Requests;

use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Services\MarketMovementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query contract for the area comparison endpoint (Map Phase 6).
 *
 * 2–3 DISTINCT public area slugs — never one (nothing to compare), never
 * four (unreadable on any screen and unbounded in spirit), and never the
 * same slug twice (`distinct` answers 422 with a real message instead of
 * silently rendering one area against itself). The market filters are the
 * movement product's own closed vocabularies, exactly MarketMapRequest's
 * discipline: transaction, the shared window list, a single optional
 * PropertyType where absence means the spanning all-categories index.
 */
final class MapCompareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'areas' => ['required', 'array', 'min:2', 'max:3'],
            'areas.*' => ['required', 'string', 'max:64', 'distinct'],
            'transaction' => ['sometimes', 'string', Rule::in(MarketMovementService::TRANSACTIONS)],
            'period' => ['sometimes', 'string', Rule::in(MarketMovementService::WINDOWS)],
            'property_type' => ['sometimes', 'nullable', 'string', Rule::enum(PropertyType::class)],
        ];
    }

    /**
     * The submitted slugs, order preserved — column order is the visitor's
     * own A/B/C choice.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        /** @var array<int, string> $areas */
        $areas = $this->validated('areas');

        return array_values($areas);
    }

    public function transactionMode(): string
    {
        return (string) $this->validated('transaction', 'sale');
    }

    public function window(): string
    {
        return (string) $this->validated('period', 'all');
    }

    public function propertyType(): ?string
    {
        $value = $this->validated('property_type');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
