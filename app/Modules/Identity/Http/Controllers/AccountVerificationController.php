<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\WhatsAppOtp;
use App\Modules\Identity\Services\WhatsAppVerificationService;
use App\Modules\Identity\Support\PostLinkDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The verification-choice surface: one account, two doors.
 *
 * Registration lands here. The page offers exactly two ways to verify the
 * SAME account — the existing Telegram Start (unchanged, one press, link
 * never expires) and a WhatsApp one-time code delivered through Bird — and
 * one success through either door is the whole requirement. The choice page
 * decides nothing itself: Telegram keeps its own page, service and webhook,
 * and the WhatsApp actions below delegate everything that matters to
 * {@see WhatsAppVerificationService}.
 *
 * The routes live beside the Telegram link routes in the `auth` +
 * `account.active` group WITHOUT the verified-account gate, because this is
 * where that gate SENDS people. A verified account arriving anywhere here is
 * simply forwarded to wherever it belongs — the same both-directions rule the
 * Telegram link page follows.
 *
 * WhatsApp appears only when Bird is configured AND the account has a phone
 * to deliver to; otherwise the page offers Telegram alone and behaves exactly
 * as the product did before this shipped.
 */
final class AccountVerificationController extends Controller
{
    public function __construct(private readonly WhatsAppVerificationService $whatsapp) {}

    /** The choice page itself. */
    public function choose(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedAccount()) {
            // Verified through either door: nothing to choose any more.
            return redirect()->to(PostLinkDestination::for($user->fresh()));
        }

        return Inertia::render('Account/VerifyChoice', [
            'telegram_available' => (string) config('services.telegram.bot_username', '') !== '',
            'whatsapp_available' => $this->whatsappAvailable($request),
            'phone_masked' => $this->maskedPhone($request),
        ]);
    }

    /** The WhatsApp code screen: request a code, type it back. */
    public function showWhatsApp(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedAccount()) {
            return redirect()->to(PostLinkDestination::for($user->fresh()));
        }

        if (! $this->whatsappAvailable($request)) {
            // Not configured, or no phone to deliver to: the choice page
            // explains what IS available.
            return redirect()->to(localized_route('account.verify'));
        }

        $live = WhatsAppOtp::query()
            ->where('user_id', $user->getKey())
            ->usable()
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Account/VerifyWhatsApp', [
            'phone_masked' => $this->maskedPhone($request),
            /*
             * State only — never the code, never a digest. `code_sent` lets a
             * refresh keep showing the entry field instead of pretending no
             * code exists; the two clocks let the page say when a resend is
             * allowed and how long the code stays good.
             */
            'code_sent' => $live !== null,
            'resend_in_seconds' => $live === null || $live->created_at === null
                ? 0
                : max(0, (int) now()->diffInSeconds(
                    $live->created_at->addSeconds(WhatsAppVerificationService::RESEND_COOLDOWN_SECONDS),
                    false,
                )),
            'expires_in_seconds' => $live === null
                ? 0
                : max(0, (int) now()->diffInSeconds($live->expires_at, false)),
        ]);
    }

    /** Mint and deliver a code. */
    public function sendWhatsApp(Request $request): RedirectResponse
    {
        $result = $this->whatsapp->send($request->user());

        if ($result['ok']) {
            return redirect()
                ->to(localized_route('account.verify.whatsapp'))
                ->with('status', __('identity.whatsapp.sent'));
        }

        if (($result['reason'] ?? '') === 'already_verified') {
            return redirect()->to(PostLinkDestination::for($request->user()->fresh()));
        }

        if (($result['reason'] ?? '') === 'cooldown') {
            /*
             * Not an error the person caused — the code they already have is
             * still on its way. Land back on the entry screen, which shows
             * the resend countdown from server state.
             */
            return redirect()->to(localized_route('account.verify.whatsapp'));
        }

        return redirect()
            ->to(localized_route('account.verify.whatsapp'))
            ->withErrors(['whatsapp' => __(match ($result['reason'] ?? '') {
                'no_phone' => 'identity.whatsapp.no_phone',
                'not_configured' => 'identity.whatsapp.unavailable',
                default => 'identity.whatsapp.send_failed',
            })]);
    }

    /** Redeem a typed code. */
    public function confirmWhatsApp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Digits only; people paste codes with stray spaces.
            'code' => ['required', 'string', 'regex:/^\s*\d{6}\s*$/'],
        ]);

        $result = $this->whatsapp->verify($request->user(), trim($validated['code']));

        if ($result['ok']) {
            /*
             * The session's privileges just changed — every personal surface
             * opens. Rotate the id, exactly as the Telegram poll does at the
             * same moment in its flow.
             */
            $request->session()->regenerate();

            $user = $request->user()->fresh();

            return redirect()
                ->to(PostLinkDestination::for($user))
                ->with('status', ($result['already_verified'] ?? false)
                    ? __('identity.whatsapp.already_verified')
                    : __('identity.whatsapp.verified'));
        }

        return redirect()
            ->to(localized_route('account.verify.whatsapp'))
            ->withErrors(['code' => __(match ($result['reason'] ?? '') {
                'no_challenge' => 'identity.whatsapp.no_code',
                'too_many_attempts' => 'identity.whatsapp.too_many_attempts',
                'phone_changed' => 'identity.whatsapp.phone_changed',
                default => 'identity.whatsapp.wrong_code',
            })]);
    }

    private function whatsappAvailable(Request $request): bool
    {
        $phone = $request->user()->phone();

        return $this->whatsapp->isConfigured() && $phone !== null && $phone !== '';
    }

    /**
     * The number a code would go to, shown back safely: enough digits to
     * recognise your own phone, never enough to identify somebody else's.
     */
    private function maskedPhone(Request $request): ?string
    {
        $phone = (string) $request->user()->phone();

        if ($phone === '') {
            return null;
        }

        $tail = substr($phone, -4);

        return '•••• ••• '.$tail;
    }
}
