<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Http\Middleware\SetLocale;
use App\Modules\Identity\Services\TelegramReturnHandoff;
use App\Modules\Identity\Support\PostLinkDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Land a person who pressed "Return to MyHawler" inside Telegram.
 *
 * The request arrives from Telegram's in-app browser with no MyHawler cookies
 * at all, so this is the one place where a token — not a session — establishes
 * who is asking. Everything that makes that safe lives in
 * {@see TelegramReturnHandoff}; this controller only spends the token and
 * decides what the person sees.
 *
 * On success the session is regenerated BEFORE authentication, exactly as
 * registration does, so a session id an attacker could have planted in that
 * browser cannot become an authenticated one.
 *
 * On any failure the person sees one neutral page telling them to go back to
 * the tab they started in. There is deliberately no distinction between an
 * expired token, a replayed token, a token for another account and a token
 * that never existed: distinguishing them would tell whoever is holding the
 * link something about an account that is not theirs.
 */
final class TelegramReturnController extends Controller
{
    public function __construct(private readonly TelegramReturnHandoff $handoff) {}

    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $result = $this->handoff->redeem($token);

        if ($result === null) {
            /*
             * Neutral, and honest: it does not claim anything about whether an
             * account exists, and it points at the browser tab that genuinely
             * does hold the session.
             */
            return redirect()
                ->to(localized_route('telegram.return.expired'))
                ->with('status', __('identity.link.return_expired'));
        }

        $user = $result['user'];
        $locale = $result['locale'];

        $request->session()->put(SetLocale::SESSION_KEY, $locale);
        app()->setLocale($locale);

        // Rotate first, authenticate second.
        $request->session()->regenerate();

        Auth::login($user, remember: true);

        /*
         * The destination is RECOMPUTED here rather than carried in the token.
         * A token that named its own target would be a redirect parameter with
         * extra steps; this one cannot point anywhere the resolver would not
         * already send this user.
         */
        return redirect()->to(PostLinkDestination::for($user, $locale));
    }

    /**
     * The neutral page a spent, expired or foreign token lands on.
     */
    public function expired(): Response
    {
        return Inertia::render('Auth/TelegramReturnExpired', ['variant' => 'expired']);
    }

    /**
     * Pre-confirmation neutral page (BLOCKER 2).
     *
     * Reached from the first Telegram message, in a browser that has no
     * session and must not be given one: authenticating a cold browser BEFORE
     * the original tab has confirmed the candidate would hand the anti-hijack
     * gate to whoever holds the link. So this page carries no token, reveals
     * no account, claims nothing about whether linking succeeded, and simply
     * points the person back to the tab they started in.
     */
    public function returnToTab(): Response
    {
        return Inertia::render('Auth/TelegramReturnExpired', ['variant' => 'return_to_tab']);
    }
}
