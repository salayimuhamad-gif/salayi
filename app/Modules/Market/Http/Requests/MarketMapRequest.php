<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Requests;

use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Services\MarketMovementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query contract for the map market-heat endpoint (Map Phase 4).
 *
 * The bbox rules are the explorer's own; transaction and period come from
 * the movement service's closed vocabularies and the category from the
 * PropertyType enum — exactly MarketMovementRequest's discipline, with one
 * deliberate difference: the category is SINGLE-valued here, because one
 * polygon paints one claim. Absent means the spanning all-categories index
 * (the product's existing honest "all"), never a blend of typed indices.
 */
final class MarketMapRequest extends FormRequest
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
            'north' => ['required', 'numeric', 'between:-90,90'],
            'south' => ['required', 'numeric', 'between:-90,90'],
            'east' => ['required', 'numeric', 'between:-180,180'],
            'west' => ['required', 'numeric', 'between:-180,180'],
            'transaction' => ['sometimes', 'string', Rule::in(MarketMovementService::TRANSACTIONS)],
            'period' => ['sometimes', 'string', Rule::in(MarketMovementService::WINDOWS)],
            'property_type' => ['sometimes', 'nullable', 'string', Rule::enum(PropertyType::class)],
        ];
    }

    /**
     * @return array{north: float, south: float, east: float, west: float}
     */
    public function bounds(): array
    {
        return [
            'north' => (float) $this->validated('north'),
            'south' => (float) $this->validated('south'),
            'east' => (float) $this->validated('east'),
            'west' => (float) $this->validated('west'),
        ];
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
