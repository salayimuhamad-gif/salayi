<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Http\Controllers;

use App\Modules\Advisor\Enums\LifestylePriorityKind;
use App\Modules\Advisor\Models\LifestyleProfile;
use App\Modules\Advisor\Services\LifestyleCandidateBuilder;
use App\Modules\Advisor\Services\LifestyleMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lifestyle search (File two §8).
 *
 * §8 is the requirement that makes this a market-intelligence product rather
 * than a listings site: a household searches by how they live, not by price and
 * district. They state where they work, where the children go to school and how
 * far they will tolerate being from their parents, and the platform ranks
 * projects against that.
 *
 * Everything below the surface already existed and was disconnected.
 * `lifestyle_profiles` and `lifestyle_priorities` were in the schema from the
 * first migration with no models. `LifestyleMatcher` was written, weighted and
 * proved against 33 assertions with no candidates to score. This controller and
 * LifestyleCandidateBuilder are what join them.
 *
 * Anonymous use is deliberate. A visitor may build a profile and see results
 * before creating an account — the profile binds to their session and is
 * claimed when they register. Requiring registration first would put the form
 * in front of the value, and the form is long.
 */
final class LifestyleController extends Controller
{
    public function __construct(private readonly LifestyleCandidateBuilder $candidates) {}

    public function edit(Request $request): Response
    {
        $profile = $this->currentProfile($request);

        return Inertia::render('Public/Lifestyle/Edit', [
            'profile' => $profile === null ? null : [
                'id' => $profile->id,
                'label' => $profile->label,
                'budget_min' => $profile->budget_min === null ? null : (string) $profile->budget_min,
                'budget_max' => $profile->budget_max === null ? null : (string) $profile->budget_max,
                'budget_currency' => $profile->budget_currency,
                'property_types' => $profile->property_types ?? [],
                'household_adults' => $profile->household_adults,
                'household_children' => $profile->household_children,
                // forPublicDisplay withholds the coordinates of sensitive kinds
                // — a workplace or a child's school is never sent to a browser
                // that only needs to know the pin is set.
                'priorities' => $profile->priorities
                    ->map(fn ($priority): array => $priority->forPublicDisplay())
                    ->values()
                    ->all(),
            ],
            'priority_kinds' => array_map(
                static fn (LifestylePriorityKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'component' => $kind->component(),
                    'specific_location' => $kind->isSpecificLocation(),
                    'sensitive' => $kind->isSensitive(),
                ],
                LifestylePriorityKind::cases(),
            ),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'gte:budget_min'],
            'budget_currency' => ['required', 'string', 'size:3'],
            'property_types' => ['nullable', 'array'],
            'property_types.*' => ['string', 'max:32'],
            'household_adults' => ['nullable', 'integer', 'between:0,20'],
            'household_children' => ['nullable', 'integer', 'between:0,20'],

            'priorities' => ['nullable', 'array', 'max:20'],
            'priorities.*.kind' => ['required', Rule::in(LifestylePriorityKind::values())],
            'priorities.*.label' => ['nullable', 'string', 'max:120'],
            'priorities.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'priorities.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'priorities.*.place_id' => ['nullable', 'integer', 'exists:places,id'],
            'priorities.*.importance' => ['required', 'integer', 'between:1,5'],
            'priorities.*.max_distance_m' => ['nullable', 'integer', 'between:100,100000'],
            'priorities.*.is_required' => ['required', 'boolean'],
        ]);

        $profile = $this->currentProfile($request) ?? new LifestyleProfile;

        $profile->fill([
            'user_id' => $request->user()?->id,
            'session_key' => $request->user() === null ? $this->sessionKey($request) : $profile->session_key,
            'label' => $validated['label'] ?? null,
            'budget_min' => $validated['budget_min'] ?? null,
            'budget_max' => $validated['budget_max'] ?? null,
            'budget_currency' => $validated['budget_currency'],
            'property_types' => $validated['property_types'] ?? [],
            'household_adults' => $validated['household_adults'] ?? null,
            'household_children' => $validated['household_children'] ?? null,
            'locale' => app()->getLocale(),
            'is_active' => true,
        ]);

        $profile->save();

        /*
         * Priorities are replaced wholesale rather than diffed. The set is
         * small, it is always submitted complete by the form, and a diff would
         * need a stable client-side id for rows the user has not saved yet —
         * which is a lot of machinery for a list of at most twenty rows.
         */
        $profile->priorities()->delete();

        foreach ($validated['priorities'] ?? [] as $priority) {
            $profile->priorities()->create([
                'kind' => $priority['kind'],
                'label' => $priority['label'] ?? null,
                'latitude' => $priority['latitude'] ?? null,
                'longitude' => $priority['longitude'] ?? null,
                'place_id' => $priority['place_id'] ?? null,
                'importance' => $priority['importance'],
                'max_distance_m' => $priority['max_distance_m'] ?? null,
                'is_required' => $priority['is_required'],
            ]);
        }

        return redirect()
            ->route('lifestyle.results')
            ->with('success', __('advisor.lifestyle.saved'));
    }

    /**
     * Ranked matches with their full component breakdown.
     *
     * §8 requires the result be explainable and not computed by a language
     * model alone. Every number on this page comes from LifestyleMatcher, and
     * every one arrives with the reason that produced it.
     */
    public function results(Request $request): Response
    {
        $profile = $this->currentProfile($request);

        if ($profile === null) {
            return Inertia::render('Public/Lifestyle/Results', [
                'has_profile' => false,
                'matches' => [],
                'weights' => [],
            ]);
        }

        return Inertia::render('Public/Lifestyle/Results', [
            'has_profile' => true,
            'matches' => $this->candidates->rank($profile, 5),
            /*
             * The weights are published on the page. A household is entitled to
             * know that budget is worth 25 and family proximity 8 before they
             * trust the ordering — a score whose weighting is hidden is not
             * explainable, whatever its breakdown says.
             */
            'weights' => LifestyleMatcher::WEIGHTS,
        ]);
    }

    private function currentProfile(Request $request): ?LifestyleProfile
    {
        return LifestyleProfile::query()
            ->active()
            ->ownedBy($request->user()?->id, $this->sessionKey($request))
            ->with(['priorities.place'])
            ->latest('id')
            ->first();
    }

    /**
     * A stable per-browser key for anonymous profiles.
     *
     * Derived from the session id rather than stored as its own cookie: it
     * inherits the session's lifetime and its rotation on login, so an
     * anonymous profile cannot outlive the session that created it.
     */
    private function sessionKey(Request $request): string
    {
        return substr(hash('sha256', $request->session()->getId()), 0, 64);
    }
}
