<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Account;

use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ProfilePhotoService;
use App\Modules\Identity\Support\ProfilePhoneConflict;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The private profile (spec §4).
 *
 * Ownership is structural, not checked: every read and write below operates
 * on `$request->user()` and nothing else, and the routes sit behind
 * `auth` + `telegram.linked`. There is no id parameter to tamper with, so
 * "a user may edit only their own profile" is a property of the shape, not
 * of a guard somebody could forget.
 *
 * The phone stays exactly what the model says it is: user-provided contact
 * data. Changing it re-encrypts and re-indexes through the same primitives
 * and RESETS `phone_verified` — a new number is unproven even when the old
 * one was proven, and quietly carrying the flag across would turn an edit
 * box into a verification bypass.
 *
 * M13 — the phone-change policy, stated in one place:
 *
 *   The number remains `user_provided` and unverified, always. A change
 *   normalises to E.164, encrypts and re-indexes in the SAME save, refuses
 *   a colliding blind index with a deliberately generic message (the
 *   specific collision goes to the security log, because "already
 *   registered" spoken to the person is an existence oracle), never merges
 *   accounts, never touches the Telegram link, and never writes the number
 *   — plaintext, masked or partial — into the audit trail.
 *
 *   Contact consent is ACCOUNT-tied, not number-tied. The consent record
 *   means "this person agreed to be contacted by the team"; the person is
 *   identified by their account and its durable Telegram link, not by
 *   whichever number they typed. A phone change therefore leaves granted
 *   consent standing, and withdrawing consent remains its own explicit act
 *   — the same per-user consent H6 records and the sales workspace reads.
 */
final class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfilePhotoService $photos,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Profile', [
            'profile' => [
                'name' => $user->name,
                'display_name' => $user->display_name,
                'preferred_locale' => $user->preferred_locale,
                'phone' => $this->maskedPhone($user),
                'profile_area_id' => $user->profile_area_id,
                'profile_bio' => $user->profile_bio,
                'contact_preference' => $user->contact_preference,
            ],
            'avatar' => $user->avatarPayload(),
            'phone_verified' => (bool) $user->phone_verified,
            'telegram_linked' => $user->telegram_verified_at !== null,
            'areas' => Area::query()
                ->where('publication_status', 'published')
                ->orderBy('name_ckb')
                ->get(['id', 'name_ckb', 'name_ar', 'name_en']),
            'contact_preferences' => ['phone', 'telegram', 'either'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge([
            'phone' => preg_replace('/[\s\-]+/', '', (string) $request->input('phone', '')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'display_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'preferred_locale' => ['required', 'in:ckb,ar,en'],
            'phone' => ['nullable', 'string', 'regex:/^(\+?964|0)?7[0-9]{9}$/'],
            /*
             * Correction v4, BLOCKER 4. `exists:areas,id` accepted ANY row
             * in the table, published or not. The page only ever offers
             * published areas, so the UI looked correct — but a hand-made
             * POST could pin a profile to a draft or archived area the
             * person was never shown, and every downstream surface that
             * joins on it would then have to cope with a row it considers
             * invisible.
             *
             * The rule is now the SAME predicate the picker uses, written
             * once here and read from the same table: published only.
             */
            'profile_area_id' => [
                'nullable',
                'integer',
                Rule::exists('areas', 'id')->where('publication_status', 'published'),
            ],
            'profile_bio' => ['nullable', 'string', 'max:500'],
            'contact_preference' => ['nullable', 'in:phone,telegram,either'],
        ]);

        $user = $request->user();

        $user->fill([
            'name' => trim($validated['name']),
            'preferred_locale' => $validated['preferred_locale'],
        ]);

        /*
         * `validate()` returns only the keys that ARRIVED; a nullable field
         * the form omitted is simply absent. `?? null` is the difference
         * between "clear it" and "crash on it".
         */
        $displayName = $validated['display_name'] ?? null;

        $user->forceFill([
            'display_name' => $displayName !== null ? trim($displayName) : null,
            'profile_area_id' => $validated['profile_area_id'] ?? null,
            'profile_bio' => $validated['profile_bio'] ?? null,
            'contact_preference' => $validated['contact_preference'] ?? null,
        ]);

        /*
         * Correction v4, BLOCKER 5. The conflict check and the save are ONE
         * transaction under the user's own row lock, and the unique index
         * is the final authority. See applyPhoneChange().
         */
        $this->saveWithPhoneChange($user, $validated['phone'] ?? null);

        $this->audit->record('identity.profile_updated', $user);

        return back()->with('status', 'profile-saved');
    }

    public function storePhoto(Request $request): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'file']]);

        // The service performs the REAL validation — content-derived type,
        // size, dimensions, decodability — and throws translated
        // ValidationExceptions for each refusal.
        $this->photos->store($request->user(), $request->file('photo'));

        $this->audit->record('identity.profile_photo_updated', $request->user());

        return back()->with('status', 'photo-saved');
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        $this->photos->remove($request->user());

        $this->audit->record('identity.profile_photo_removed', $request->user());

        return back()->with('status', 'photo-removed');
    }

    /**
     * Persist the profile, including any phone change, as ONE atomic
     * decision.
     *
     * Correction v4, BLOCKER 5. The v3 sequence was: blind-index existence
     * check, mutate the model, save later. No lock, no transaction, no
     * catch. Two requests changing to the same number both passed the
     * check; one then hit the unique index and the person got a 500 page
     * for what is, in truth, an ordinary validation outcome.
     *
     * Three layers now, and the third is the one that actually closes it:
     *
     *   1. The user's own row is locked, so two saves by the SAME account
     *      serialise instead of interleaving.
     *   2. The blind-index pre-check runs inside that transaction, so it
     *      sees every committed change rather than a stale read.
     *   3. The UNIQUE index on `users.phone_index` remains the ground
     *      truth for the window no lock on THIS row can cover — two
     *      DIFFERENT accounts racing for the same number. Losing that race
     *      is CAUGHT and converted to the identical translated validation
     *      error the pre-check produces.
     *
     * The two paths returning the same generic message is the privacy
     * requirement, not a convenience: a distinguishable "already
     * registered" is an existence oracle, and a distinguishable "you lost
     * a race" tells the loser that somebody else is registering that exact
     * number right now.
     *
     * The audit records the conflict with neither the number, nor a masked
     * form of it, nor the blind index — a blind index is a stable
     * identifier for a phone number, and putting it in the audit trail
     * would make the trail a lookup table for the encrypted column.
     */
    private function saveWithPhoneChange(User $user, ?string $input): void
    {
        try {
            DB::transaction(function () use ($user, $input): void {
                User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                $this->applyPhoneChange($user, $input);

                $user->save();
            });
        } catch (ProfilePhoneConflict) {
            /*
             * v5: the PRE-CHECK conflict, audited where the rollback cannot
             * reach it. v4 wrote this record inside applyPhoneChange() —
             * inside the transaction its own ValidationException then
             * aborted — so the trail showed the race-loser conflicts and
             * silently lost the common ones.
             */
            $this->audit->security('identity.profile_phone_conflict', [
                'actor_id' => $user->getKey(),
                'via' => 'pre_check',
            ]);

            throw ValidationException::withMessages([
                'phone' => __('identity.profile.phone_unusable'),
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Another account won the number between the pre-check and the
             * write. The transaction rolled back completely, so nothing —
             * name, locale, bio, area — was saved either; the person
             * resubmits and everything applies. The audit lands outside the
             * dead transaction so the refusal survives it.
             */
            $this->audit->security('identity.profile_phone_conflict', [
                'actor_id' => $user->getKey(),
                'via' => 'unique_constraint',
            ]);

            throw ValidationException::withMessages([
                'phone' => __('identity.profile.phone_unusable'),
            ]);
        }
    }

    /**
     * A changed number is stored under the same encryption and the same blind
     * index as everywhere else — and refused, generically, when the index
     * already belongs to another account.
     *
     * Callers must hold the transaction and the row lock; this method is the
     * decision, {@see saveWithPhoneChange()} is the serialisation.
     */
    private function applyPhoneChange(User $user, ?string $input): void
    {
        if ($input === null || $input === '') {
            return;
        }

        $phone = $this->toE164($input);
        $index = User::blindIndex($phone);

        if ($index === $user->phone_index) {
            return; // Unchanged — a no-op, not a conflict.
        }

        $taken = User::query()
            ->where('phone_index', $index)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            // v5: signal only — the audit belongs OUTSIDE this transaction,
            // where saveWithPhoneChange() records it after the rollback.
            throw new ProfilePhoneConflict;
        }

        $user->setPhone($phone);

        /*
         * A new number is unproven even when the old one was proven, and
         * the Telegram link is untouched — it identifies the ACCOUNT, not
         * the number.
         */
        $user->forceFill(['phone_verified' => 0]);
    }

    /**
     * Enough of the stored number for the owner to recognise it, never the
     * whole thing — the page should not be a plaintext copy of an encrypted
     * column.
     */
    private function maskedPhone(User $user): ?string
    {
        $phone = $user->phone();

        if ($phone === null || $phone === '') {
            return null;
        }

        return substr($phone, 0, 4).'•••'.substr($phone, -3);
    }

    private function toE164(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');

        if (! str_starts_with($digits, '964')) {
            $digits = '964'.$digits;
        }

        return '+'.$digits;
    }
}
