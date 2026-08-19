<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Identity\Support\PermissionRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }

    protected function bootModule(): void
    {
        /*
         * Every catalogue permission becomes a Gate ability, so both
         * `@can('projects.publish')` in a template and `$this->authorize(...)`
         * in a controller resolve through the same registry.
         */
        foreach (PermissionRegistry::all() as $permission) {
            Gate::define($permission, static fn ($user): bool => $user->hasPermission($permission));
        }

        // Super Admin bypasses every gate — including future ones added by a
        // module that forgets to register its own.
        Gate::before(static fn ($user): ?bool => $user->isSuperAdmin() ? true : null);

        Password::defaults(fn (): Password => Password::min((int) config('mulkihawler.security.password_min_length', 8)));

        $this->registerRateLimits();
    }

    /**
     * Spec 30.1: rate limiting and login throttling.
     *
     * Keyed on the identifier AND ip together. Identifier-only lets one
     * attacker lock out a known administrator by failing their login on
     * purpose; ip-only lets a botnet spread attempts across addresses. Both
     * keys are registered so either alone trips the limit.
     *
     * The field is `login` — an email OR a phone number — since customers
     * registered with a phone and no email. It is HASHED into the key rather
     * than embedded: the limiter's keys end up in the cache store, and a phone
     * number is personal data that has no business being one. A digest buckets
     * identically and reveals nothing.
     */
    private function registerRateLimits(): void
    {
        $attempts = (int) config('mulkihawler.security.login_throttle.attempts', 5);
        $decay = (int) config('mulkihawler.security.login_throttle.decay_minutes', 15);

        RateLimiter::for('login', static fn (Request $request): array => [
            Limit::perMinutes($decay, $attempts)
                ->by('login:id:'.hash('sha256', mb_strtolower((string) $request->input('login')))),
            Limit::perMinutes($decay, $attempts * 4)->by('login:ip:'.$request->ip()),
        ]);

        /*
         * An UNAUTHENTICATED request is precisely what these limiters exist
         * for, so falling back to the IP is the real path, not a defensive
         * afterthought. The analyser infers the configured guard's model as
         * always present; `Auth::id()` is nullable by contract and says what
         * actually happens.
         */
        RateLimiter::for('mfa', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by('mfa:'.(Auth::id() ?? $request->ip())));

        RateLimiter::for('password-reset', static fn (Request $request): Limit => Limit::perMinutes(60, 5)
            ->by('pwreset:'.mb_strtolower((string) $request->input('email'))));

        /*
         * Telegram recovery requests: each one can put a message in somebody's
         * chat, so the budget is tighter than the email broker's — three an
         * hour per phone digest stops a harasser spamming one person's chat,
         * and the IP ceiling stops one machine sweeping many numbers. Keyed by
         * digest, not the raw number, like every other identifier throttle.
         */
        RateLimiter::for('password-recovery', static fn (Request $request): array => [
            Limit::perHour(3)
                ->by('pwrecover:phone:'.hash('sha256', preg_replace('/\D+/', '', (string) $request->input('phone')) ?? '')),
            Limit::perHour(10)->by('pwrecover:ip:'.$request->ip()),
        ]);

        // Redemption attempts per IP. The per-TOKEN budget (five tries, then
        // the token stops answering) lives in TelegramPasswordRecovery.
        RateLimiter::for('password-recovery-redeem', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('pwrecover-redeem:'.$request->ip()));

        /*
         * The poll runs every second or two while somebody switches to
         * Telegram, so the limit is generous — but per session, not global,
         * so one impatient browser cannot exhaust the allowance for everyone.
         */
        /*
         * Form submissions mint bearer tokens and write encrypted rows, so
         * they are budgeted tighter than polls: five per ten minutes per IP is
         * a person retrying a typo, not a script farming intents.
         */
        // The link poll heartbeats every couple of seconds for up to ten
        // minutes; sixty per minute per user is generous headroom for one
        // tab and still a ceiling for a runaway loop.
        RateLimiter::for('telegram-link-poll', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('link-poll:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('registration', static fn (Request $request): Limit => Limit::perMinutes(10, 5)
            ->by('registration|'.$request->ip()));

        // Image processing is the priciest request the account surface
        // serves; ten per minute is a person retrying, not a flood.
        RateLimiter::for('profile-photo', static function (Request $request): Limit {
            // The photo routes sit behind `auth`, and Laravel's middleware
            // priority runs authentication before throttling — so by the time
            // this closure executes there IS a user. The ip() fallback exists
            // for the impossible case anyway; the nullsafe read of user was
            // the part stan correctly called dead.
            $user = $request->user();

            return Limit::perMinute(10)
                ->by('profile-photo|'.($user !== null ? $user->id : $request->ip()));
        });

        /*
         * A valuation is a few hundred rows and arithmetic — cheap, but not
         * free on shared hosting, and nobody honest needs it forty times a
         * minute. Six per minute per account bounds the worst case.
         */
        RateLimiter::for('portfolio-valuation', static function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(6)
                ->by('portfolio-valuation|'.($user !== null ? $user->id : $request->ip()));
        });

        /*
         * §7's rate-limit sweep: the media upload re-encodes images through
         * GD exactly like the profile photo does, so it gets the same class
         * of ceiling — generous for a person organising a property, hostile
         * to a loop. Same shape as its siblings: per-user, ip fallback for
         * the unauthenticated-impossible case.
         */
        RateLimiter::for('portfolio-media', static function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(15)
                ->by('portfolio-media|'.($user !== null ? $user->id : $request->ip()));
        });

        /*
         * Advisor safety (Phase 14). One /advisor/reply can trigger up to
         * TWO paid AI completions plus two advisor_messages rows, AiGateway
         * carries no per-user control at all, and the monthly cost ceiling
         * defaults to unlimited — so before this limiter, one scripted
         * Telegram-linked account was an open-ended cost vector. Ten a
         * minute is well above a fast human conversation (~4-6 messages)
         * and sits inside the house's risk-graduated scale (valuation 6,
         * media 15). Per-user key; the ip fallback covers only the
         * unauthenticated-impossible case, exactly like its siblings.
         *
         * The custom response mirrors AdvisorController::reply()'s own
         * dual-mode error shape, so the throttled turn surfaces as the
         * SAME kind of message a failed turn does: localized JSON for the
         * chat's fetch path, a redirect with an error bag — which the chat
         * page already renders — for a plain Inertia post. The framework
         * throttle layer still attaches Retry-After.
         */
        RateLimiter::for('advisor-reply', static function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)
                ->by('advisor-reply|'.($user !== null ? $user->id : $request->ip()))
                ->response(static fn (Request $blocked, array $headers) => $blocked->expectsJson()
                    ? response()->json(['message' => __('advisor.chat.rate_limited')], 429, $headers)
                    : back()->withErrors(['message' => __('advisor.chat.rate_limited')]));
        });

        /*
         * The advisor's cheap mutations (amend, reset, request): no AI
         * involved, but reset+show mints a conversation row per loop
         * iteration and request writes a lead — generous for a person,
         * hostile to a script. recommend (a no-op redirect) and the GET
         * page are deliberately unthrottled.
         */
        RateLimiter::for('advisor-write', static function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(30)
                ->by('advisor-write|'.($user !== null ? $user->id : $request->ip()))
                ->response(static fn (Request $blocked, array $headers) => $blocked->expectsJson()
                    ? response()->json(['message' => __('advisor.chat.rate_limited')], 429, $headers)
                    : back()->withErrors(['message' => __('advisor.chat.rate_limited')]));
        });

        /*
         * WhatsApp OTP delivery: every send puts a PAID message into
         * somebody's chat, so the budget is the recovery channel's, not the
         * poll's — three an hour per account stops a loop burning money and
         * spamming one person's WhatsApp, and the IP ceiling stops one
         * machine sweeping accounts. The per-minute resend cooldown on top
         * of this lives in the service, where it can answer with the exact
         * seconds remaining.
         */
        RateLimiter::for('whatsapp-otp-send', static fn (Request $request): array => [
            Limit::perHour(3)->by('wa-otp-send:user:'.($request->user()->id ?? $request->ip())),
            Limit::perHour(10)->by('wa-otp-send:ip:'.$request->ip()),
        ]);

        /*
         * Code redemption attempts per account. The per-CODE budget (five
         * tries, then the code is burnt) lives in WhatsAppVerificationService
         * under the row lock; this bucket only bounds how fast a script can
         * churn through fresh codes.
         */
        RateLimiter::for('whatsapp-otp-confirm', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('wa-otp-confirm:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('telegram-poll', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->session()->getId()));

        /*
         * Telegram batches and retries, so this is high. It exists to bound a
         * flood from something pretending to be Telegram — those requests fail
         * the secret check anyway, but they should not be free.
         */
        RateLimiter::for('telegram-webhook', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->ip() ?? 'unknown'));

        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) (Auth::id() ?? $request->ip())));

        // The installer is unauthenticated by necessity. A tight limit is the
        // only control available before the first admin exists.
        RateLimiter::for('install', static fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()));
    }
}
