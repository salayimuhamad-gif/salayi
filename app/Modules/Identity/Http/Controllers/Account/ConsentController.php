<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Account;

use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contact-consent self service (correction v4, BLOCKER 6).
 *
 * The gap this closes: registration and advisor submission could both
 * CREATE `company_contact` consent, and `PhoneRevealService` depends on it
 * to release a number — but nothing in the delivered product let the person
 * take it back. The profile page offered `contact_preference`, which is a
 * different thing entirely: a preference says "call me on Telegram rather
 * than by phone", a consent says "you may contact me at all". Treating the
 * first as the second means a person who set every preference to its
 * quietest value still had a live, legally-recorded permission on file.
 *
 * The model is append-only, and deliberately so. A withdrawal does not
 * delete the grant and does not edit it; it writes a NEW row recording the
 * opposite decision. Proving what somebody had agreed to on a given date
 * needs the history, not the current state, and a product that erases the
 * grant when consent is withdrawn destroys its own evidence that consent
 * was ever properly obtained.
 *
 * Ownership is structural: every query below is scoped to
 * `$request->user()`, there is no id in any route, and the routes sit
 * behind `auth` + `account.active`. One person cannot reach another's
 * consent because there is no parameter through which to try.
 */
final class ConsentController extends Controller
{
    /**
     * The consent types a person may manage themselves.
     *
     * `account_registration` is deliberately absent: it records that the
     * account was created on stated terms, and withdrawing it would be
     * account deletion wearing a checkbox. That is a different feature with
     * different obligations, and conflating them here would let somebody
     * destroy their own account by unticking a privacy box.
     */
    private const SELF_SERVICE_TYPES = ['company_contact'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Privacy', [
            'consents' => collect(self::SELF_SERVICE_TYPES)
                ->map(fn (string $type): array => $this->state($user, $type))
                ->values()
                ->all(),
            'policy_version' => (string) config('mulkihawler.registration.policy_version', '2026-08-01'),
        ]);
    }

    /**
     * Withdraw contact consent.
     *
     * Effective immediately and everywhere: `PhoneRevealService` re-reads
     * the consent rows under a lock on every single reveal, so the next
     * attempt — including one already in flight and waiting on that lock —
     * sees this row and refuses. There is no cache to invalidate and no
     * denormalised flag to keep in step, which is exactly why the reveal
     * path was built to ask the table rather than read a boolean.
     */
    public function withdraw(Request $request): RedirectResponse
    {
        $this->record($request, granted: false);

        return back()->with('status', 'consent-withdrawn');
    }

    /** Grant it again, as a new decision with its own evidence row. */
    public function grant(Request $request): RedirectResponse
    {
        $this->record($request, granted: true);

        return back()->with('status', 'consent-granted');
    }

    /**
     * Append one consent decision.
     *
     * Under the user's row lock, because a decision taken twice in two tabs
     * must not produce two rows claiming to be the latest, and because the
     * reveal path locks the same row — the two serialise against each other
     * rather than racing.
     *
     * `granted_at` is set on BOTH kinds of row, including withdrawals, and
     * that is load-bearing rather than sloppy: `ConsentGate::latestFor()`
     * orders by `granted_at` to decide which record supersedes which. A
     * withdrawal written with a null `granted_at` would sort to 1970 and be
     * silently outranked by the grant it was meant to revoke — the
     * withdrawal would appear to work, and the number would still be
     * revealable. The column is the decision timestamp, not a
     * grant-only timestamp.
     */
    private function record(Request $request, bool $granted): void
    {
        $user = $request->user();
        $type = self::SELF_SERVICE_TYPES[0];
        $locale = $user->preferred_locale ?? app()->getLocale();

        DB::transaction(function () use ($request, $user, $type, $granted, $locale): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $now = now();

            Consent::query()->create([
                'user_id' => $user->getKey(),
                'type' => $type,
                'granted' => $granted,
                'source' => 'account_privacy_page',
                'evidence' => [
                    'policy_version' => (string) config('mulkihawler.registration.policy_version', '2026-08-01'),
                    'method' => $granted ? 'self_service_grant' : 'self_service_withdrawal',
                ],
                'ip_hash' => $request->ip() === null ? null : hash('sha256', $request->ip()),
                'user_agent_hash' => $request->userAgent() === null
                    ? null
                    : hash('sha256', $request->userAgent()),
                'locale' => $locale,
                'granted_at' => $now,
                'withdrawn_at' => $granted ? null : $now,
            ]);
        });

        /*
         * Audited outside the transaction, and carrying no contact detail —
         * no number, no masked number, no blind index. The event is that a
         * decision changed; the decision itself lives in the consents table
         * where it belongs.
         */
        $this->audit->record(
            $granted ? 'identity.consent_granted' : 'identity.consent_withdrawn',
            $user,
            [],
            ['type' => $type],
            severity: 'warning',
        );
    }

    /**
     * The current state of one consent type, resolved the SAME way
     * `ConsentGate` resolves it — newest decision wins.
     *
     * Duplicating the gate's ordering rule here would be a bug waiting to
     * happen (a page saying "withdrawn" while reveals still succeed is
     * worse than no page), so this reads the newest row by the same key the
     * gate sorts on.
     *
     * @return array{type: string, granted: bool, decided_at: string|null, history_count: int}
     */
    private function state(User $user, string $type): array
    {
        $rows = Consent::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->get();

        $current = $rows->first();

        return [
            'type' => $type,
            'granted' => $current !== null
                && (bool) $current->granted === true
                && $current->withdrawn_at === null,
            'decided_at' => $current?->granted_at?->toIso8601String(),
            'history_count' => $rows->count(),
        ];
    }
}
