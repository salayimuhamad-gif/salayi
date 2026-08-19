<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Support\UnsubscribeToken;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Operations\Support\Redactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Honours the unsubscribe link every notification carries (spec 22.3, 30.2).
 *
 * Public and unauthenticated by design. Requiring a login to stop being
 * contacted is the most common way this promise is quietly broken: the person
 * who wants the messages to stop is frequently the person least willing to
 * remember a password for a product they are trying to leave.
 *
 * GET CONFIRMS, POST ACTS. Telegram, and every mail client, fetch link previews
 * automatically — a GET that unsubscribed on sight would silently opt people
 * out because a preview bot looked at the message. So the link shows a page,
 * and a deliberate human action performs the withdrawal.
 *
 * Withdrawal is written as a new `consents` row rather than an edit, because
 * consent is append-only (spec 30.2) and `ConsentGate` resolves the newest
 * record of a type. That means this endpoint needs no special case anywhere in
 * the dispatcher: the next send simply finds a withdrawal and refuses.
 */
final class UnsubscribeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The confirmation page. Performs nothing. */
    public function show(Request $request, string $token): Response
    {
        $claim = UnsubscribeToken::verify($token);

        if ($claim === null) {
            // No reason given, and the same page for "malformed" and "unknown
            // user", so the endpoint cannot be used to enumerate accounts.
            return Inertia::render('Public/Unsubscribe', [
                'status' => 'invalid',
                'token' => null,
                'purpose' => null,
                'purposes' => [],
            ]);
        }

        $user = User::query()->find($claim['user_id']);

        if ($user === null) {
            return Inertia::render('Public/Unsubscribe', [
                'status' => 'invalid',
                'token' => null,
                'purpose' => null,
                'purposes' => [],
            ]);
        }

        return Inertia::render('Public/Unsubscribe', [
            'status' => $this->alreadyWithdrawn($user, $claim['purpose']) ? 'already' : 'confirm',
            'token' => $token,
            'purpose' => $claim['purpose'],
            'purposes' => $this->purposeLabels($claim['purpose']),
            // Stated plainly rather than buried: some notices cannot be
            // switched off, and finding that out afterwards feels like a trick.
            'transactional_notice' => __('notifications.unsubscribe.transactional_notice'),
        ]);
    }

    /** Perform the withdrawal. */
    public function store(Request $request, string $token): RedirectResponse
    {
        $claim = UnsubscribeToken::verify($token);

        if ($claim === null) {
            return back()->with('error', __('notifications.unsubscribe.invalid'));
        }

        $user = User::query()->find($claim['user_id']);

        if ($user === null) {
            return back()->with('error', __('notifications.unsubscribe.invalid'));
        }

        $types = UnsubscribeToken::consentTypesFor($claim['purpose']);
        $now = now();

        foreach ($types as $type) {
            Consent::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'granted' => false,
                'source' => 'unsubscribe_link',
                'evidence' => ['purpose' => $claim['purpose'], 'token_issued_at' => $claim['issued_at']],
                'ip_hash' => Redactor::hashIp($request->ip()),
                'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
                'locale' => $user->preferred_locale,
                'granted_at' => $now,
                'withdrawn_at' => $now,
            ]);
        }

        // Severity 'warning': a consent withdrawal is a decision the platform
        // must be able to prove it honoured, and an auditor asked "when did
        // they opt out" needs to find this without reading every info row.
        $this->audit->record('notification.unsubscribed', $user, [], [
            'purpose' => $claim['purpose'],
            'consent_types' => $types,
        ], severity: 'warning');

        return back()->with('success', __('notifications.unsubscribe.done'));
    }

    /**
     * Undo, using the same token.
     *
     * Present because unsubscribing by accident is common and the alternative
     * is a support request. It re-grants only what the token names, so it can
     * never be used to grant a consent the recipient never had.
     */
    public function resubscribe(Request $request, string $token): RedirectResponse
    {
        $claim = UnsubscribeToken::verify($token);

        if ($claim === null) {
            return back()->with('error', __('notifications.unsubscribe.invalid'));
        }

        $user = User::query()->find($claim['user_id']);

        if ($user === null) {
            return back()->with('error', __('notifications.unsubscribe.invalid'));
        }

        foreach (UnsubscribeToken::consentTypesFor($claim['purpose']) as $type) {
            Consent::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'granted' => true,
                'source' => 'unsubscribe_link_undo',
                'evidence' => ['purpose' => $claim['purpose']],
                'ip_hash' => Redactor::hashIp($request->ip()),
                'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
                'locale' => $user->preferred_locale,
                'granted_at' => now(),
                'withdrawn_at' => null,
            ]);
        }

        $this->audit->record('notification.resubscribed', $user, [], [
            'purpose' => $claim['purpose'],
        ], severity: 'warning');

        return back()->with('success', __('notifications.unsubscribe.resubscribed'));
    }

    /** Whether every consent this token covers is already off. */
    private function alreadyWithdrawn(User $user, string $purpose): bool
    {
        $types = UnsubscribeToken::consentTypesFor($purpose);

        foreach ($types as $type) {
            $latest = Consent::query()
                ->where('user_id', $user->id)
                ->where('type', $type)
                ->orderByDesc('granted_at')
                ->orderByDesc('id')
                ->first();

            // No record at all is not "already unsubscribed" — it is simply
            // never granted, and the page should still offer the action so the
            // recipient gets an explicit, provable refusal on file.
            if ($latest !== null && (bool) $latest->granted === true && $latest->withdrawn_at === null) {
                return false;
            }
        }

        return $types !== [];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function purposeLabels(string $purpose): array
    {
        $types = $purpose === 'all'
            ? UnsubscribeToken::UNSUBSCRIBABLE
            : [$purpose];

        return array_map(
            static fn (string $p): array => [
                'value' => $p,
                'label' => __('notifications.purposes.'.$p),
            ],
            $types,
        );
    }
}
