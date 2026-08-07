<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Http\Controllers;

use App\Modules\Advisor\Models\AdvisorConversation;
use App\Modules\Advisor\Services\AdvisorConversationFlow;
use App\Modules\Advisor\Services\AdvisorConversationService;
use App\Modules\Advisor\Services\AdvisorLanguage;
use App\Modules\Advisor\Services\AiGateway;
use App\Modules\Identity\Models\Consent;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Leads\Services\AdvisorLeadRecorder;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Live multilingual residential advisor. */
final class AdvisorController extends Controller
{
    private const CRITERIA_KEY = 'advisor.criteria';

    private const SLOT_KEY = 'advisor.current_slot';

    private const LANGUAGE_KEY = 'advisor.language';

    public function __construct(
        private readonly AdvisorConversationService $conversations,
        private readonly AdvisorConversationFlow $flow,
        private readonly AdvisorLanguage $language,
        private readonly AiGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): Response
    {
        $criteria = (array) $request->session()->get(self::CRITERIA_KEY, []);
        // The language tab / localized URL is authoritative. An old session
        // value must never make a Kurdish page answer in Arabic.
        $locale = $this->language->normalize(app()->getLocale());
        $userName = $this->firstName($request);
        $conversation = $this->conversations->open(
            $request->user()?->id,
            $this->sessionKey($request),
            'residential',
            $locale,
        );

        // A language switch starts a clean transcript while preserving the
        // already collected criteria. Also retire a transcript created by an
        // older patch if it contains a foreign-locale assistant turn or leaked
        // internal field names such as required_question.
        $foreignAssistant = $conversation->messages()
            ->where('role', 'assistant')
            ->where(function ($query) use ($locale): void {
                $query->whereNull('locale')->orWhere('locale', '!=', $locale);
            })
            ->exists();
        $internalLeak = $conversation->messages()
            ->where('role', 'assistant')
            ->where(function ($query): void {
                $query->where('content', 'like', '%required_question%')
                    ->orWhere('content', 'like', '%known_criteria%')
                    ->orWhere('content', 'like', '%visitor_message%');
            })
            ->exists();

        if ($this->language->normalize($conversation->locale) !== $locale
            || $foreignAssistant
            || $internalLeak) {
            $conversation->forceFill([
                'status' => 'closed',
                'last_message_at' => now(),
            ])->save();

            $conversation = $this->conversations->open(
                $request->user()?->id,
                $this->sessionKey($request),
                'residential',
                $locale,
            );
        }

        $conversation->forceFill(['locale' => $locale])->save();
        $request->session()->put(self::LANGUAGE_KEY, $locale);

        // v1 could persist the user turn before a slow provider terminated the
        // request. Repair that one orphaned turn once, using a fast local reply.
        $recovered = $this->conversations->recoverLastUnansweredTurn($conversation, $criteria);

        if ($recovered !== null) {
            $criteria = $recovered['criteria'];
            $locale = $recovered['locale'];
            $request->session()->put(self::CRITERIA_KEY, $criteria);
            $request->session()->put(self::SLOT_KEY, $recovered['next_slot']);
            $request->session()->put(self::LANGUAGE_KEY, $locale);
        }

        $nextSlot = $this->flow->nextSlot($criteria);
        $request->session()->put(self::SLOT_KEY, $nextSlot);

        $recommendations = null;

        if ($nextSlot === null) {
            try {
                $recommendations = $this->conversations->recommendations($criteria, $locale);
            } catch (Throwable $exception) {
                Log::warning('advisor.recommendations_unavailable', [
                    'conversation_id' => $conversation->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        $messages = $this->transcript($conversation);
        $last = $messages === [] ? null : $messages[array_key_last($messages)];
        $initialMessage = ($last === null || $last['role'] !== 'assistant')
            ? $this->conversations->initialMessage($conversation, $nextSlot, $userName)
            : null;

        return Inertia::render('Public/Advisor/Chat', [
            'conversation_id' => $conversation->public_id,
            'conversation_locale' => $locale,
            'next_slot' => $nextSlot,
            'complete' => $nextSlot === null,
            'progress' => $this->flow->progress($criteria),
            'summary' => $this->conversations->summary($criteria, $locale),
            'messages' => $messages,
            'initial_message' => $initialMessage,
            'quick_replies' => $this->conversations->quickReplies($nextSlot, $locale, $criteria),
            'recommendations' => $recommendations,
            'user_first_name' => $userName,
            // The chat renders the PERSON on their own turns (spec §4.2):
            // processed photo when one exists, initials material otherwise.
            // Never a storage path — avatarPayload() emits public URLs only.
            'user_avatar' => $request->user()->avatarPayload(),
            // The submit-to-team state for THIS conversation (spec §7.3):
            // null until submitted, then the lead's stage — so a refresh
            // shows "submitted" instead of offering the button again.
            'request_status' => DemandProfile::query()
                ->where('advisor_conversation_id', $conversation->id)
                ->value('stage'),
            'ai_available' => $this->gateway->isAvailable(),
        ]);
    }

    public function reply(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'locale' => ['nullable', 'string', 'in:ckb,ar,en'],
        ]);

        $criteria = (array) $request->session()->get(self::CRITERIA_KEY, []);
        $slot = $request->session()->get(self::SLOT_KEY);

        if (! is_string($slot)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('advisor.chat.live.already_complete')], 409);
            }

            return redirect(localized_route('advisor.show'));
        }

        $locale = $this->language->normalize(
            $validated['locale'] ?? app()->getLocale(),
            (string) $request->session()->get(self::LANGUAGE_KEY, 'ckb'),
        );
        $request->session()->put(self::LANGUAGE_KEY, $locale);

        $conversation = $this->conversations->open(
            $request->user()?->id,
            $this->sessionKey($request),
            'residential',
            $locale,
        );
        $conversation->forceFill(['locale' => $locale])->save();

        try {
            // Accept and save interview state before touching the external AI.
            $state = $this->conversations->acceptReply(
                $conversation,
                $criteria,
                $slot,
                $validated['message'],
                $locale,
            );
            $request->session()->put(self::CRITERIA_KEY, $state['criteria']);
            $request->session()->put(self::SLOT_KEY, $state['next_slot']);
            $request->session()->put(self::LANGUAGE_KEY, $state['locale']);
            $request->session()->save();

            $assistant = $this->conversations->answerAccepted(
                $conversation,
                $validated['message'],
                $state['next_slot'],
                $state['locale'],
                $state['criteria'],
                $this->firstName($request),
            );
            $result = $this->conversations->result($state, $assistant);
        } catch (Throwable $exception) {
            Log::error('advisor.reply_failed', [
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('advisor.chat.send_failed'),
                ], 500, ['Cache-Control' => 'no-store']);
            }

            return redirect(localized_route('advisor.show'))->withErrors([
                'message' => __('advisor.chat.send_failed'),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'assistant_message' => $result['assistant_message'],
                'next_slot' => $result['next_slot'],
                'complete' => $result['complete'],
                'progress' => $result['progress'],
                'summary' => $result['summary'],
                'conversation_locale' => $result['locale'],
                'recommendations' => $result['recommendations'],
                'quick_replies' => $this->conversations->quickReplies(
                    $result['next_slot'],
                    $result['locale'],
                    $result['criteria'],
                ),
                'ai_available' => $this->gateway->isAvailable(),
            ], 200, ['Cache-Control' => 'no-store']);
        }

        return redirect(localized_route('advisor.show'));
    }

    public function amend(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slot' => ['required', 'string', 'max:32'],
            'value' => ['nullable', 'string', 'max:500'],
        ]);

        $slotKeys = array_column($this->flow->slots(), 'key');

        if (! in_array($validated['slot'], $slotKeys, true)) {
            return redirect(localized_route('advisor.show'));
        }

        $criteria = (array) $request->session()->get(self::CRITERIA_KEY, []);
        $criteria[$validated['slot']] = $validated['value'];

        $request->session()->put(self::CRITERIA_KEY, $criteria);
        $request->session()->put(self::SLOT_KEY, $this->flow->nextSlot($criteria));

        return redirect(localized_route('advisor.show'));
    }

    public function recommend(Request $request): RedirectResponse
    {
        // Results are generated inside the chat as soon as the interview is
        // complete. Keep this legacy endpoint as a safe no-op for older cached
        // frontends instead of redirecting the person to a different flow.
        return redirect(localized_route('advisor.show'));
    }

    /**
     * "Send my request to the MyHawler team" (spec §7.3).
     *
     * Three things happen, in an order that matters: the interview must
     * actually CONTAIN something (the three required slots), the contact
     * consent is recorded ONCE — an existing active grant is reused, never
     * duplicated — and only then does the recorder upsert the lead, where the
     * database's unique key makes a retry land on the same row.
     */
    public function submit(Request $request, AdvisorLeadRecorder $recorder): JsonResponse|RedirectResponse
    {
        $request->validate(['consent' => ['accepted']]);

        $criteria = (array) $request->session()->get(self::CRITERIA_KEY, []);
        $locale = $this->language->normalize(app()->getLocale());
        $user = $request->user();

        $conversation = $this->conversations->open(
            $user->id,
            $this->sessionKey($request),
            'residential',
            $locale,
        );

        /*
         * Correction H6, readiness: objective, type, budget — AND an area
         * answer. "Answer" includes the explicit no-preference reply, which
         * IS the person accepting alternatives; what it excludes is silence,
         * because a request that names no geography and never chose to skip
         * it is not yet a request anyone can act on.
         */
        foreach (['purpose', 'budget', 'property_type', 'area'] as $slot) {
            $value = $criteria[$slot] ?? null;

            if ($value === null || (is_string($value) && trim($value) === '')) {
                return $request->expectsJson()
                    ? response()->json(['ok' => false, 'reason' => 'incomplete'], 422)
                    : back()->withErrors(['request' => __('advisor.submit.incomplete')]);
            }
        }

        $summary = $this->conversations->summary($criteria, $locale);

        /*
         * H6: ONE unit. The recorder's transaction now carries the consent
         * with it — the lead and the consent commit together or not at all,
         * and a retry lands on the already-created lead. Audit stays after,
         * best-effort, per the house contract.
         */
        $lead = $recorder->record(
            $conversation,
            $user,
            $criteria,
            array_values(array_map(
                static fn (array $row): string => ($row['key'] ?? '').': '.(string) ($row['value'] ?? ''),
                array_filter($summary, static fn (array $row): bool => ($row['answered'] ?? false) === true),
            )),
            $locale,
            [
                'ip_hash' => $request->ip() === null ? null : hash('sha256', $request->ip()),
                'user_agent_hash' => $request->userAgent() === null ? null : hash('sha256', (string) $request->userAgent()),
                'conversation_public_id' => $conversation->public_id,
                'policy_version' => (string) config('mulkihawler.registration.policy_version', '2026-08-01'),
            ],
        );

        $this->audit->record('leads.advisor_request_submitted', $lead, [], [
            'conversation_id' => $conversation->public_id,
        ]);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'stage' => $lead->stage])
            : back()->with('status', 'request-submitted');
    }

    public function reset(Request $request): RedirectResponse
    {
        $query = AdvisorConversation::query()
            ->where('agent', 'residential')
            ->where('status', 'open');

        if ($request->user() !== null) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->whereNull('user_id')->where('session_key', $this->sessionKey($request));
        }

        $query->update([
            'status' => 'closed',
            'last_message_at' => now(),
        ]);

        $request->session()->forget([self::CRITERIA_KEY, self::SLOT_KEY, self::LANGUAGE_KEY]);

        return redirect(localized_route('advisor.show'));
    }

    /** @return list<array<string, mixed>> */
    private function transcript(AdvisorConversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn ($message): array => $this->conversations->messagePayload($message))
            ->values()
            ->all();
    }

    private function firstName(Request $request): ?string
    {
        // The advisor routes sit behind `auth`, so user() cannot be null here;
        // the nullsafe read was survivorship from the live-server patch.
        $name = trim((string) $request->user()->name);

        if ($name === '') {
            return null;
        }

        $first = preg_split('/\s+/u', $name, 2)[0] ?? $name;
        $first = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $first));

        return $first === '' ? null : mb_substr($first, 0, 40);
    }

    private function sessionKey(Request $request): string
    {
        return substr(hash('sha256', $request->session()->getId()), 0, 64);
    }
}
