<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use App\Modules\Companies\Enums\AssociationRole;
use App\Modules\Geography\Rules\PublishableArea;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Rules\ValidWkt;
use Illuminate\Validation\Rule;

/**
 * The wizard's steps, their order, and their validation rules.
 *
 * One place, because the same definition has to answer four questions that
 * must never disagree: which step comes next, which fields that step owns,
 * what makes it valid, and whether the wizard can finish. Splitting them
 * across a controller and a request class is how a step ends up validated on
 * save and not on submit.
 *
 * Rules are per-step and are applied BOTH when the step is saved and again in
 * full when the wizard is submitted. A draft is stored data and stored data is
 * not trusted: a payload can be old enough that its area was since deleted, or
 * edited directly by anyone who could reach the row.
 */
final class WizardStep
{
    public const IDENTITY = 'identity';

    public const DEVELOPER = 'developer';

    public const LOCATION = 'location';

    public const DETAILS = 'details';

    public const PRICING = 'pricing';

    public const MEDIA = 'media';

    public const REVIEW = 'review';

    /** @return list<string> in wizard order */
    public static function all(): array
    {
        return [
            self::IDENTITY,
            self::DEVELOPER,
            self::LOCATION,
            self::DETAILS,
            self::PRICING,
            self::MEDIA,
            self::REVIEW,
        ];
    }

    public static function exists(string $step): bool
    {
        return in_array($step, self::all(), true);
    }

    public static function next(string $step): ?string
    {
        $steps = self::all();
        $index = array_search($step, $steps, true);

        return $index === false ? null : ($steps[$index + 1] ?? null);
    }

    public static function previous(string $step): ?string
    {
        $steps = self::all();
        $index = array_search($step, $steps, true);

        return $index === false || $index === 0 ? null : $steps[$index - 1];
    }

    /**
     * Steps that must be complete before the wizard may finish.
     *
     * DEVELOPER IS OPTIONAL and is no longer listed. Every rule on that step
     * is nullable, so an empty submission satisfied all of them and the step
     * was marked complete having collected nothing — a required step that
     * anything passes is not a requirement, it is a click. Erbil projects are
     * frequently entered before the developer is confirmed, so making it
     * mandatory would block real records; it is therefore honestly optional.
     *
     * MEDIA is absent for the same reason it always was: a project with no
     * photographs is a thin record, not an invalid one.
     *
     * @return list<string>
     */
    public static function required(): array
    {
        return [self::IDENTITY, self::LOCATION, self::DETAILS];
    }

    /**
     * Steps a visitor may complete but is never blocked on.
     *
     * @return list<string>
     */
    public static function optional(): array
    {
        return array_values(array_diff(self::all(), [...self::required(), self::REVIEW]));
    }

    /**
     * Whether a step's payload carries meaningful data.
     *
     * Validation alone cannot answer this: a step whose rules are all nullable
     * passes on an empty array. A REQUIRED step must contain something, and
     * what "something" means differs per step — so it is stated here rather
     * than inferred from the rules.
     *
     * @param  array<string, mixed>  $values
     */
    public static function isMeaningful(string $step, array $values): bool
    {
        $filled = array_filter(
            $values,
            static fn ($value): bool => $value !== null && $value !== '' && $value !== [],
        );

        return match ($step) {
            // A location is a POINT or a POLYGON. Neither is optional: a
            // project nobody can place on a map cannot be compared to
            // anything, which is most of what this product does.
            self::LOCATION => isset($filled['latitude'], $filled['longitude'])
                || isset($filled['boundary_wkt']),
            self::IDENTITY => isset($filled['name_ckb'], $filled['project_type']),
            self::DETAILS => isset($filled['construction_status'], $filled['delivery_status']),
            default => $filled !== [],
        };
    }

    /**
     * Validation rules for one step.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $step): array
    {
        return match ($step) {
            self::IDENTITY => [
                // Sorani is the authoring language and is therefore the only
                // required name (spec 7.1). Requiring all three would mean a
                // project cannot be entered until somebody translates it,
                // which is how records stop being entered at all.
                'name_ckb' => ['required', 'string', 'max:191'],
                'name_ar' => ['nullable', 'string', 'max:191'],
                'name_en' => ['nullable', 'string', 'max:191'],
                'slug' => ['nullable', 'string', 'max:191', 'regex:/^[a-z0-9\-]+$/'],
                // Rule::enum, not a loose string. A free-text project_type
                // passes validation and then throws during Eloquent enum
                // casting on save — a 500 at the end of a long form, after
                // every step has already been accepted.
                'project_type' => ['required', Rule::enum(ProjectType::class)],
            ],
            self::DEVELOPER => [
                /*
                 * `exists` only proves the id is real. Whether this editor may
                 * ATTACH that developer is checked in the controller against
                 * the acting company's associations — a select is presentation,
                 * not a constraint.
                 */
                'developer_id' => ['nullable', 'integer', 'exists:developers,id'],
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                // The association's role is meaningless without the company,
                // and a company association with no stated role is exactly the
                // ambiguity §11.2 asks to be removed.
                // Enum-validated. An unknown role reaching the association
                // table is a commercial claim nobody made.
                'association_role' => ['nullable', Rule::enum(AssociationRole::class), 'required_with:company_id'],
            ],
            self::LOCATION => [
                // Coordinates are required together or not at all. A latitude
                // with no longitude is not a partial location, it is a broken
                // one, and it would place the project on the prime meridian.
                'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
                // Published AND publishable ancestry — the same rule the
                // legacy form and the options lists use.
                'area_id' => ['nullable', 'integer', new PublishableArea],
                // Records whether the person chose the area themselves or
                // applied the wizard's suggestion — different provenance,
                // different trust.
                'area_was_suggested' => ['nullable', 'boolean'],
                // Geometry is parsed, not merely length-checked. A malformed,
                // open or degenerate ring passes `string` and then produces a
                // boundary that renders as nonsense or not at all.
                'boundary_wkt' => ['nullable', 'string', 'max:200000', new ValidWkt],
            ],
            self::DETAILS => [
                'construction_status' => ['required', Rule::enum(ConstructionStatus::class)],
                'delivery_status' => ['required', Rule::enum(DeliveryStatus::class)],
                'completion_percent' => ['nullable', 'integer', 'between:0,100'],
                'expected_delivery' => ['nullable', 'date'],
                'unit_count' => ['nullable', 'integer', 'min:0'],
            ],
            self::PRICING => [
                // Price TYPE is required whenever a price is given. An asking
                // price recorded as though it were a transaction is the single
                // most damaging data error this product can make.
                /*
                 * price_from anchors the record. A payload carrying only
                 * price_to, or only provenance, would otherwise be stored and
                 * then silently dropped by persistPricing() — data a person
                 * typed and confirmed, gone without a word.
                 */
                'price_from' => [
                    'nullable', 'numeric', 'min:0',
                    'required_with:price_to,currency,price_type,price_period,price_effective_date,price_source,price_confidence',
                ],
                'price_to' => ['nullable', 'numeric', 'min:0', 'gte:price_from'],
                'currency' => ['nullable', 'string', 'size:3'],
                'price_type' => ['nullable', Rule::enum(PriceType::class), 'required_with:price_from'],
                'price_period' => ['nullable', 'string', 'max:16'],
                'price_effective_date' => ['nullable', 'date'],
                'price_source' => ['nullable', 'string', 'max:64'],
                'price_confidence' => ['nullable', 'string', Rule::in(['low', 'medium', 'high'])],
            ],
            self::MEDIA => [
                /*
                 * NO client-supplied media ids. Uploads are draft-owned rows
                 * written by uploadMedia() and promoted at submission, so
                 * there is no id to craft. Only the caption carried on the
                 * step remains.
                 */
                'media_note' => ['nullable', 'string', 'max:500'],
            ],
            default => [],
        };
    }
}
