<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Account;

use App\Modules\Identity\Support\ProfileLocationOptions;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Post-link onboarding (spec §5).
 *
 * Resumable by construction: it renders whatever the account currently holds,
 * saves whatever the person confirms, and never repeats the Telegram link —
 * the `telegram.linked` gate on the route already proved that part, and this
 * page's job is only the human details on top of it.
 *
 * The status badges state each claim at its true strength: Telegram is
 * VERIFIED because a Start behind the webhook secret proved it; the phone is
 * PROVIDED because typing a number proves nothing. Rendering both, side by
 * side, is what keeps the mandatory-Telegram model honest.
 */
final class OnboardingController extends Controller
{
    private const PURPOSES = ['buying', 'investment', 'living', 'selling', 'portfolio'];

    /**
     * The gender values offered.
     *
     * A short list with an explicit "prefer not to say", and no business rule
     * anywhere reads it — it is profile colour, not a gate. Kept here rather
     * than in an enum column so changing it is a product decision, not a
     * migration.
     */
    private const GENDERS = ['female', 'male', 'undisclosed'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $locale = app()->getLocale();

        return Inertia::render('Account/Onboarding', [
            'display_name' => $user->display_name ?? $user->name,
            'preferred_locale' => $user->preferred_locale ?? 'ckb',
            'primary_purpose' => $user->primary_purpose,
            'purposes' => self::PURPOSES,
            'locales' => ['ckb', 'ar', 'en'],
            'completed' => $user->onboarding_completed_at !== null,

            /*
             * Screen 3 of the simplified journey: the optional details.
             *
             * Every one of these is nullable and none of them gates anything.
             * They live here rather than on the registration form on purpose —
             * a sign-up that asks for a date of birth and a photo is a sign-up
             * people abandon — and the page makes clear they can be left alone.
             */
            'email' => $user->email,
            'gender' => $user->gender,
            'genders' => self::GENDERS,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'profile_area_id' => $user->profile_area_id,
            // Derived, never stored: see ProfileLocationOptions.
            'profile_city_id' => ProfileLocationOptions::cityIdFor($user->profile_area_id),
            'cities' => ProfileLocationOptions::cities($locale),
            'areas' => ProfileLocationOptions::areas($locale),
            'status' => [
                'telegram_verified' => $user->telegram_verified_at !== null,
                'phone_provided' => $user->phone_index !== null,
                // Never claimed from a Start; true only if a Share-Contact
                // login ever proved it.
                'phone_verified' => (bool) $user->phone_verified,
                'contact_consent' => $user->consents()
                    ->where('type', 'company_contact')
                    ->where('granted', true)
                    ->exists(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'preferred_locale' => ['required', 'in:ckb,ar,en'],
            'primary_purpose' => ['nullable', 'in:'.implode(',', self::PURPOSES)],
            'next' => ['required', 'in:advisor,portfolio,home'],

            /*
             * Optional, every one of them, and validated as strictly as if
             * they were not — "optional" governs whether a value is required,
             * never whether it is checked.
             */
            'email' => [
                'nullable', 'string', 'email', 'max:190',
                /*
                 * `users.email` is UNIQUE, so a collision here would surface as
                 * a 500 on save. Ignoring the current row lets somebody
                 * re-submit the page with their own address unchanged.
                 */
                Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'gender' => ['nullable', 'in:'.implode(',', self::GENDERS)],
            /*
             * A date, in the past, and belonging to somebody who could
             * plausibly be using the platform. The upper bound stops a
             * mistyped year creating an account that claims to be an infant;
             * the lower bound catches a transposed century. Neither is a
             * business rule about who may register — nothing reads this field
             * to decide anything.
             */
            'date_of_birth' => ['nullable', 'date', 'before:'.now()->subYears(13)->toDateString(), 'after:'.now()->subYears(120)->toDateString()],
            /*
             * The SAME predicate the picker uses, read from the same table:
             * published only. `exists:areas,id` would accept a draft or
             * archived area that the page never offered, pinning a profile to
             * a row every downstream surface considers invisible — the exact
             * fault already corrected once on the profile screen.
             */
            'profile_area_id' => [
                'nullable',
                'integer',
                Rule::exists('areas', 'id')->where('publication_status', 'published'),
            ],
        ]);

        $user->forceFill([
            'display_name' => trim($validated['display_name']),
            'preferred_locale' => $validated['preferred_locale'],
            'primary_purpose' => $validated['primary_purpose'] ?? null,
            /*
             * Blank submissions clear the field rather than being ignored:
             * emptying an optional detail is a thing people legitimately want
             * to do, and treating blank as "no change" makes it impossible.
             */
            'email' => $this->nullIfBlank($validated['email'] ?? null),
            'gender' => $validated['gender'] ?? null,
            'date_of_birth' => $this->nullIfBlank($validated['date_of_birth'] ?? null),
            'profile_area_id' => $validated['profile_area_id'] ?? null,
            'onboarding_completed_at' => $user->onboarding_completed_at ?? now(),
        ])->save();

        /*
         * WHICH fields were filled in, never their values. A date of birth or
         * an email address in the audit trail is personal data duplicated
         * somewhere the encryption and the retention policy do not reach.
         */
        $this->audit->record('identity.onboarding_completed', $user, [], [
            'email_provided' => $user->email !== null,
            'gender_provided' => $user->gender !== null,
            'date_of_birth_provided' => $user->date_of_birth !== null,
            'area_provided' => $user->profile_area_id !== null,
        ]);

        // Named, localized destinations (§6.6): the person who registered
        // in Arabic continues in Arabic.
        return redirect(match ($validated['next']) {
            'advisor' => localized_route('advisor.show'),
            'portfolio' => localized_route('portfolio.index'),
            default => localized_route('home'),
        });
    }

    /**
     * An empty string from a cleared input is "no value", not the value "".
     *
     * `users.email` is UNIQUE and nullable: storing `''` would let exactly one
     * account hold the empty address and make the second one collide with it.
     */
    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
