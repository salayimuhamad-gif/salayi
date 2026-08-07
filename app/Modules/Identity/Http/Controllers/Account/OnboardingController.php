<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Account;

use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Onboarding', [
            'display_name' => $user->display_name ?? $user->name,
            'preferred_locale' => $user->preferred_locale ?? 'ckb',
            'primary_purpose' => $user->primary_purpose,
            'purposes' => self::PURPOSES,
            'locales' => ['ckb', 'ar', 'en'],
            'completed' => $user->onboarding_completed_at !== null,
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
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'preferred_locale' => ['required', 'in:ckb,ar,en'],
            'primary_purpose' => ['nullable', 'in:'.implode(',', self::PURPOSES)],
            'next' => ['required', 'in:advisor,portfolio,home'],
        ]);

        $user = $request->user();

        $user->forceFill([
            'display_name' => trim($validated['display_name']),
            'preferred_locale' => $validated['preferred_locale'],
            'primary_purpose' => $validated['primary_purpose'] ?? null,
            'onboarding_completed_at' => $user->onboarding_completed_at ?? now(),
        ])->save();

        $this->audit->record('identity.onboarding_completed', $user);

        // Named, localized destinations (§6.6): the person who registered
        // in Arabic continues in Arabic.
        return redirect(match ($validated['next']) {
            'advisor' => localized_route('advisor.show'),
            'portfolio' => localized_route('portfolio.index'),
            default => localized_route('home'),
        });
    }
}
