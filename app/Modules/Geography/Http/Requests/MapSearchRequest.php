<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query contract for the unified map search (Map Phase 5).
 *
 * The bounds are the same as the investment search's: at least two
 * characters (one-character noise never reaches the database), at most 80
 * (a pasted document is a 422, not a scan). Trimming is the framework's
 * global middleware; normalization is deliberately NOT here — the service
 * folds through SoraniText::searchKey(), the single normalizer.
 */
final class MapSearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ];
    }

    public function query_(): string
    {
        return trim((string) $this->validated('q'));
    }
}
