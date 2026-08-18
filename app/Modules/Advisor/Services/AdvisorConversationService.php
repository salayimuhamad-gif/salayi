<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Models\AdvisorConversation;
use App\Modules\Advisor\Models\AdvisorMessage;
use App\Modules\Advisor\Models\LifestyleProfile;
use App\Modules\Advisor\Support\AdvisorCurrency;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the advisor interview deterministically while using AI only to phrase a
 * friendly answer. User criteria are accepted separately from the provider call
 * so a slow or unavailable AI can never lose the submitted answer.
 */
final class AdvisorConversationService
{
    /**
     * The value stored in `criteria['currency']` when the person answered
     * the currency question but named nothing this product supports.
     *
     * A sentinel rather than null so the flow can tell "asked and not
     * resolved" apart from "not asked yet" — the first must not re-ask,
     * the second must.
     */
    public const CURRENCY_UNKNOWN = 'unknown';

    /**
     * v5, BLOCKER 6: the parked bare figure awaiting a unit. Underscored
     * because it is INTERVIEW state, not an answer — it is not a slot,
     * the summary never lists it, and `AdvisorLeadRecorder` maps no
     * column from it, so it can never leak into a lead.
     */
    public const BUDGET_CLARIFY = '_budget_clarify';

    public function __construct(
        private readonly AdvisorConversationFlow $flow,
        private readonly AdvisorAnswerComposer $composer,
        private readonly LifestyleCandidateBuilder $candidates,
        private readonly AdvisorLanguage $language,
        private readonly AdvisorTurnComposer $turns,
        private readonly AdvisorProjectMatcher $projectMatcher,
        private readonly AdvisorAdvisoryComposer $advisory,
        private readonly AdvisorInsightRetriever $insights,
    ) {}

    public function open(?int $userId, string $sessionKey, string $agent, string $locale): AdvisorConversation
    {
        $existing = AdvisorConversation::query()
            ->where('agent', $agent)
            ->where('status', 'open')
            ->where(function ($query) use ($userId, $sessionKey): void {
                if ($userId !== null) {
                    $query->where('user_id', $userId);
                }

                $query->orWhere(function ($anon) use ($sessionKey): void {
                    $anon->whereNull('user_id')->where('session_key', $sessionKey);
                });
            })
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return AdvisorConversation::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'session_key' => $userId === null ? $sessionKey : null,
            'agent' => $agent,
            'locale' => $locale,
            'status' => 'open',
        ]);
    }

    /**
     * Persist and interpret the visitor message without calling the AI provider.
     *
     * A null $answeringSlot means no question is pending — the advisory stage
     * — and only the opportunistic extractors run. The message is persisted
     * either way, BEFORE any model is consulted, so a provider failure can
     * never lose what the person typed.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function acceptReply(
        AdvisorConversation $conversation,
        array $criteria,
        ?string $answeringSlot,
        string $text,
        ?string $preferredLocale = null,
    ): array {
        $locale = $preferredLocale === null
            ? $this->language->detect($text, $conversation->locale)
            : $this->language->normalize($preferredLocale, $conversation->locale);
        $finishEarly = $this->isFinishCommand($text) && $this->flow->isComplete($criteria);

        return DB::transaction(function () use ($conversation, $criteria, $answeringSlot, $text, $locale, $finishEarly): array {
            $userMessage = AdvisorMessage::query()->create([
                'advisor_conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $text,
                'locale' => $locale,
                'content_class' => 'fact',
            ]);

            $criteria = $finishEarly
                ? $this->markRemainingOptionalAsSkipped($criteria, $locale)
                : $this->mergeReply($criteria, $answeringSlot, $text);
            $next = $this->flow->nextSlot($criteria);

            $conversation->forceFill([
                'locale' => $locale,
                'last_message_at' => now(),
            ])->save();

            return [
                'criteria' => $criteria,
                'next_slot' => $next,
                'locale' => $locale,
                'user_message_id' => $userMessage->id,
            ];
        });
    }

    /**
     * Produce and persist the assistant message for an already accepted reply.
     * This method always returns a renderable payload, even when AI or message
     * persistence fails.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function answerAccepted(
        AdvisorConversation $conversation,
        string $userText,
        ?string $nextSlot,
        string $locale,
        array $criteria = [],
        ?string $userName = null,
    ): array {
        $recommendations = null;

        try {
            if ($nextSlot === null) {
                $recommendations = $this->projectMatcher->recommend($criteria, $locale);
                $turn = $this->turns->finalRecommendation($recommendations, $locale);
            } else {
                $turn = $this->turns->compose($userText, $nextSlot, $locale, $criteria, $userName);
            }
        } catch (Throwable $exception) {
            Log::warning('advisor.live_turn_failed', [
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
            ]);
            $turn = $this->turns->fallbackTurn(
                $nextSlot,
                $locale,
                'unexpected_failure',
                $criteria,
                $userName,
            );
        }

        $payload = $this->persistTurnOrPayload($conversation, $turn);

        if ($recommendations !== null) {
            $payload['recommendations'] = $recommendations;
        }

        return $payload;
    }

    /**
     * Backward-compatible one-call API used by tests or other callers.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function reply(
        AdvisorConversation $conversation,
        array $criteria,
        string $answeringSlot,
        string $text,
    ): array {
        $state = $this->acceptReply($conversation, $criteria, $answeringSlot, $text);
        $assistant = $this->answerAccepted(
            $conversation,
            $text,
            $state['next_slot'],
            $state['locale'],
            $state['criteria'],
        );

        return $this->result($state, $assistant);
    }

    /**
     * Fold a validated turn-understanding structure into the criteria.
     *
     * The model PROPOSES; the flow's own merge rules DISPOSE. A slot that is
     * already answered is only overwritten when the person is explicitly
     * correcting or changing something — stated intent, or the advisory stage,
     * where every criteria remark is by nature a revision. The budget never
     * arrives through the model at all (see AdvisorTurnUnderstanding): in the
     * advisory stage it is re-parsed deterministically from the person's own
     * words, under the same BLOCKER 6 unit rules as the interview.
     *
     * @param  array<string, mixed>  $criteria
     * @param  array{intent: string, criteria_updates: array<string, mixed>, referenced_positions: list<int>, needs_clarification: bool}|null  $understanding
     * @return array{criteria: array<string, mixed>, intent: string, positions: list<int>}
     */
    public function integrateUnderstanding(
        array $criteria,
        ?array $understanding,
        string $text,
        bool $advisory,
    ): array {
        $intent = $understanding['intent'] ?? $this->fallbackIntent($text);
        $positions = ($understanding['referenced_positions'] ?? []) !== []
            ? $understanding['referenced_positions']
            : $this->fallbackPositions($text);

        $overwriteAllowed = $advisory
            || in_array($intent, ['correct_info', 'change_criteria', 'ask_cheaper'], true);

        foreach (($understanding['criteria_updates'] ?? []) as $slot => $value) {
            $answered = ($criteria[$slot] ?? null) !== null && $criteria[$slot] !== '';

            if (! $answered || $overwriteAllowed) {
                $criteria = $this->flow->merge($criteria, (string) $slot, $value);
            }
        }

        if ($advisory) {
            $criteria = $this->applyAdvisoryBudget($criteria, $text);
        }

        return [
            'criteria' => $criteria,
            'intent' => $intent,
            'positions' => $positions,
        ];
    }

    /**
     * Whether a turn actually changed the recommendation inputs.
     *
     * Underscored keys are interview state, not criteria; a parked
     * clarification appearing or clearing must not force a re-rank.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function criteriaChanged(array $before, array $after): bool
    {
        $strip = static fn (array $criteria): array => array_filter(
            $criteria,
            static fn ($value, string $key): bool => ! str_starts_with($key, '_'),
            ARRAY_FILTER_USE_BOTH,
        );

        return $strip($before) !== $strip($after);
    }

    /**
     * Produce and persist the advisory-stage assistant turn.
     *
     * Two shapes: a turn that CHANGED the inputs (or arrives with no stored
     * card context, e.g. after a session refresh) re-runs the deterministic
     * matcher and returns fresh recommendations with an update message; a
     * question about the standing cards is answered from those cards alone.
     *
     * @param  array<string, mixed>  $criteria
     * @param  list<int>  $positions
     * @param  array<string, mixed>|null  $recommendationContext  the last recommendation payload
     * @return array{payload: array<string, mixed>, recommendations: array<string, mixed>|null}
     */
    public function advisoryAnswer(
        AdvisorConversation $conversation,
        string $userText,
        array $criteria,
        string $locale,
        string $intent,
        array $positions,
        bool $criteriaChanged,
        ?array $recommendationContext,
    ): array {
        $recommendations = null;

        try {
            if ($criteriaChanged || $recommendationContext === null) {
                $recommendations = $this->projectMatcher->recommend($criteria, $locale);
                $turn = $this->advisory->compose(
                    $intent,
                    $userText,
                    $recommendations,
                    $positions,
                    $locale,
                    criteriaChanged: true,
                    insights: $this->insightsFor($intent, $criteria, $userText, $recommendations),
                );
            } else {
                $turn = $this->advisory->compose(
                    $intent,
                    $userText,
                    $recommendationContext,
                    $positions,
                    $locale,
                    criteriaChanged: false,
                    insights: $this->insightsFor($intent, $criteria, $userText, $recommendationContext),
                );
            }
        } catch (Throwable $exception) {
            Log::warning('advisor.advisory_turn_failed', [
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
            ]);
            $turn = $this->turns->fallbackTurn(null, $locale, 'unexpected_failure', $criteria);
        }

        return [
            'payload' => $this->persistTurnOrPayload($conversation, $turn),
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Market insights, retrieved ONLY for a market-question turn (Phase 12).
     *
     * Every other intent skips the query entirely — retrieval cost is paid
     * exactly where the evidence is used, and no other answer's grounding
     * changes shape.
     *
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>|null  $recommendations
     * @return list<KnowledgeEvent>
     */
    private function insightsFor(string $intent, array $criteria, string $userText, ?array $recommendations): array
    {
        if ($intent !== 'market_question') {
            return [];
        }

        return $this->insights->retrieve($criteria, $userText, $recommendations);
    }

    /**
     * The advisory-stage budget revision, deterministic by design.
     *
     * "What if my budget is 180 million IQD?" must update the number — but
     * only under the interview's own unit rules: an explicit scale word, a
     * literal marker, or digits ≥ 1000 with a known currency. A bare "180"
     * changes nothing; the advisory composer's ask_cheaper reply asks for the
     * unit instead of guessing one.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function applyAdvisoryBudget(array $criteria, string $text): array
    {
        $stated = AdvisorCurrency::detect($text)['currency'];

        if ($stated !== null) {
            $criteria['currency'] = $stated;
        }

        $amount = $this->parseAmount($text);

        if ($amount === null) {
            return $criteria;
        }

        $currencyKnown = AdvisorCurrency::storable(
            is_string($criteria['currency'] ?? null) ? $criteria['currency'] : null
        ) !== null;

        if ($amount['has_unit']
            || $amount['literal']
            || ((float) $amount['value'] >= 1000 && $currencyKnown)) {
            unset($criteria[self::BUDGET_CLARIFY]);
            $criteria['budget'] = $amount['value'];
        }

        return $criteria;
    }

    /**
     * Intent when no model is available — keyword recognition over the three
     * languages, deliberately covering the advisory quick-reply labels so the
     * chips keep working with the AI off.
     */
    public function fallbackIntent(string $text): string
    {
        $normalised = mb_strtolower(trim($text));

        $before = [
            'finish' => ['thank', 'شكرا', 'سوپاس', 'خلص', 'تەواو بوو'],
            'compare' => ['compare', 'بەراورد', 'قارن', 'مقارن'],
            'ask_cheaper' => ['cheaper', 'أرخص', 'ارخص', 'هەرزانتر', 'هەرزان'],
        ];

        foreach ($before as $intent => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalised, $needle)) {
                    return $intent;
                }
            }
        }

        /*
         * Phase 12: between ask_cheaper and explain, deliberately. "Why did
         * prices rise in Ankawa?" contains an explain keyword (بۆچی/why), but
         * the person is asking about the MARKET, not about a card — checked
         * here so the market conjunction wins, while a plain "why the first
         * one?" still reaches explain untouched.
         */
        if ($this->isMarketQuestion($normalised)) {
            return 'market_question';
        }

        $after = [
            'explain' => ['why', 'بۆچی', 'ليش', 'لماذا'],
            'project_question' => ['instalment', 'installment', 'أقساط', 'اقساط', 'قیست', 'قسط'],
        ];

        foreach ($after as $intent => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalised, $needle)) {
                    return $intent;
                }
            }
        }

        return 'other';
    }

    /**
     * The deterministic market-question recognizer (Phase 12), for when no
     * model is available. Deliberately a CONJUNCTION, never a single keyword:
     * a market subject (prices, the market) must meet a movement/state word
     * ("why did prices RISE", "what is HAPPENING with prices"), or a buy word
     * must meet an assessment word ("a GOOD place to BUY now"). A lone
     * "price" stays with whatever intent owns it today, so nothing existing
     * is stolen.
     */
    private function isMarketQuestion(string $normalised): bool
    {
        $subject = ['price', 'market', 'نرخ', 'بازاڕ', 'سعر', 'أسعار', 'اسعار', 'سوق', 'غلاء'];
        $movement = [
            'rise', 'rose', 'rising', 'going up', 'went up', 'go up', 'increase', 'jump',
            'fall', 'fell', 'falling', 'drop', 'decrease', 'happen', 'trend', 'change', 'changing',
            'بەرزبو', 'بەرز بو', 'زیادبو', 'زیادی کرد', 'دابەزی', 'هەڵکشا', 'گۆڕا', 'دەگۆڕێت',
            'چی بەسەر', 'چ ڕوودەدات', 'ڕوودەدات', 'باری بازاڕ',
            'ارتفع', 'يرتفع', 'ارتفاع', 'ترتفع', 'انخفض', 'ينخفض', 'انخفاض', 'نزل', 'صعد',
            'غلت', 'رخصت', 'شنو صاير', 'شصاير', 'يصير ب', 'ماذا يحدث', 'شنو يصير', 'وضع السوق',
        ];
        $buy = ['buy', 'invest', 'کڕین', 'بکڕم', 'بیکڕم', 'وەبەرهێنان', 'شراء', 'أشتري', 'اشتري', 'استثمار', 'استثمر'];
        $assessment = [
            'good place', 'good time', 'good area', 'worth', 'a good',
            'شوێنێکی باش', 'کاتێکی باش', 'ناوچەیەکی باش', 'باشە بۆ',
            'مكان زين', 'وكت زين', 'منطقة زينة', 'زينة لل', 'زين لل', 'مناسبة لل', 'مناسب لل', 'جيدة لل', 'جيد لل',
        ];

        $has = static function (array $needles) use ($normalised): bool {
            foreach ($needles as $needle) {
                if (str_contains($normalised, $needle)) {
                    return true;
                }
            }

            return false;
        };

        return ($has($subject) && $has($movement)) || ($has($buy) && $has($assessment));
    }

    /**
     * Referenced card positions when no model is available: ordinals in the
     * three languages, then bare digits 1–4.
     *
     * @return list<int>
     */
    public function fallbackPositions(string $text): array
    {
        $normalised = mb_strtolower($text);
        $positions = [];

        $ordinals = [
            1 => ['first', 'یەکەم', 'الأول', 'الاول'],
            2 => ['second', 'دووەم', 'الثاني', 'الثانى'],
            3 => ['third', 'سێیەم', 'الثالث'],
            4 => ['fourth', 'چوارەم', 'الرابع'],
        ];

        foreach ($ordinals as $position => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalised, $needle)) {
                    $positions[] = $position;

                    break;
                }
            }
        }

        if ($positions === [] && preg_match_all('/(?<!\d)([1-4])(?!\d)/', $normalised, $matches) > 0) {
            foreach ($matches[1] as $digit) {
                $positions[] = (int) $digit;
            }
        }

        return array_values(array_unique($positions));
    }

    /**
     * Repair a user turn that was stored by v1 but never received an assistant
     * message because the provider request terminated. Returns null when there
     * is nothing safe to recover.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>|null
     */
    public function recoverLastUnansweredTurn(AdvisorConversation $conversation, array $criteria): ?array
    {
        $last = $conversation->messages()->latest('id')->first();

        if ($last === null || $last->role !== 'user' || $last->created_at?->isAfter(now()->subSeconds(4))) {
            return null;
        }

        $slot = $this->flow->nextSlot($criteria);

        if ($slot === null) {
            return null;
        }

        return DB::transaction(function () use ($conversation, $criteria, $last, $slot): ?array {
            $lockedConversation = AdvisorConversation::query()->lockForUpdate()->find($conversation->id);

            if ($lockedConversation === null) {
                return null;
            }

            $newerAssistantExists = AdvisorMessage::query()
                ->where('advisor_conversation_id', $conversation->id)
                ->where('id', '>', $last->id)
                ->where('role', 'assistant')
                ->exists();

            if ($newerAssistantExists) {
                return null;
            }

            $locale = $this->language->normalize($lockedConversation->locale);
            $criteria = $this->mergeReply($criteria, $slot, $last->content);
            $next = $this->flow->nextSlot($criteria);
            $turn = $this->turns->fallbackTurn(
                $next,
                $locale,
                'recovered_unanswered_turn',
                $criteria,
            );
            $assistant = $this->persistTurnOrPayload($lockedConversation, $turn);

            $lockedConversation->forceFill([
                'locale' => $locale,
                'last_message_at' => now(),
            ])->save();

            return $this->result([
                'criteria' => $criteria,
                'next_slot' => $next,
                'locale' => $locale,
            ], $assistant);
        });
    }

    /** @return array<string, mixed> */
    public function initialMessage(
        AdvisorConversation $conversation,
        ?string $nextSlot,
        ?string $userName = null,
    ): array {
        return [
            'id' => 'initial-'.$conversation->public_id,
            'role' => 'assistant',
            'content' => $this->language->initialPrompt($nextSlot, $conversation->locale, $userName),
            'content_class' => '',
            'is_withheld' => false,
            'withheld_reason' => null,
            'model' => null,
            'evidence_count' => 0,
            'locale' => $conversation->locale,
        ];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array{label: string, value: string, icon: string}>
     */
    public function quickReplies(?string $nextSlot, string $locale, array $criteria): array
    {
        return $this->language->quickReplies(
            $nextSlot,
            $locale,
            $nextSlot !== null && $this->flow->isComplete($criteria),
            $criteria,
        );
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public function summary(array $criteria, string $locale): array
    {
        return $this->language->localizeSummary($this->flow->summary($criteria), $locale);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function recommendations(array $criteria, string $locale): array
    {
        return $this->projectMatcher->recommend($criteria, $locale);
    }

    /** @return array<string, mixed> */
    public function messagePayload(AdvisorMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            // Classification is retained in the database for audit, but is
            // internal metadata and must not appear as chips such as “Scenario”.
            'content_class' => '',
            'is_withheld' => (bool) $message->is_withheld,
            'withheld_reason' => $message->withheld_reason,
            'model' => $message->model,
            'evidence_count' => count((array) ($message->evidence_ids ?? [])),
            'locale' => $message->locale,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $assistant
     * @return array<string, mixed>
     */
    public function result(array $state, array $assistant): array
    {
        return [
            'criteria' => $state['criteria'],
            'next_slot' => $state['next_slot'],
            'complete' => $state['next_slot'] === null,
            // Intake completeness and conversation life are DIFFERENT states
            // (the audit's root cause conflated them): `complete` says the
            // checklist is satisfied; `stage` says which kind of turn comes
            // next. Nothing here ever closes the conversation.
            'stage' => $state['next_slot'] === null ? 'advisory' : 'intake',
            'progress' => $this->flow->progress($state['criteria']),
            'summary' => $this->language->localizeSummary(
                $this->flow->summary($state['criteria']),
                $state['locale'],
            ),
            'locale' => $state['locale'],
            'assistant_message' => $assistant,
            'recommendations' => $assistant['recommendations']
                ?? ($state['next_slot'] === null
                    ? $this->projectMatcher->recommend($state['criteria'], $state['locale'])
                    : null),
        ];
    }

    /**
     * Produce the recommendation once the interview is complete.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function recommend(
        AdvisorConversation $conversation,
        LifestyleProfile $profile,
        array $criteria,
        ?int $userId = null,
    ): array {
        $matches = $this->candidates->rank($profile, 5);

        $answer = $this->composer->compose(
            matches: $matches,
            evidence: $this->evidenceFor($matches),
            question: $this->questionFrom($criteria),
            locale: $conversation->locale,
            userId: $userId,
        );

        $this->persistAnswer($conversation, $answer);

        return $answer;
    }

    /** @param array<string, mixed> $answer */
    private function persistAnswer(AdvisorConversation $conversation, array $answer): void
    {
        $withheld = $answer['answer'] === null;

        AdvisorMessage::query()->create([
            'advisor_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $answer['answer'] ?? json_encode($answer['deterministic_summary'], JSON_UNESCAPED_UNICODE),
            'locale' => $conversation->locale,
            'content_class' => 'analysis',
            'evidence_ids' => $answer['evidence_ids'] ?? [],
            'prompt_version' => $answer['prompt_version'] ?? null,
            'model' => $answer['model'] ?? null,
            'provider' => $answer['provider'] ?? null,
            'responded_at' => now(),
            'validation_result' => $withheld ? 'withheld' : 'passed',
            'validation_detail' => $answer['validation'] ?? null,
            'cost_usd' => $answer['cost_usd'] ?? '0',
            'latency_ms' => $answer['latency_ms'] ?? 0,
            'is_withheld' => $withheld,
            'withheld_reason' => $answer['fallback_reason'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $turn
     * @return array<string, mixed>
     */
    private function persistTurnOrPayload(AdvisorConversation $conversation, array $turn): array
    {
        try {
            $assistant = AdvisorMessage::query()->create([
                'advisor_conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $turn['content'],
                'locale' => $turn['locale'],
                'content_class' => $turn['content_class'] ?? 'scenario',
                'evidence_ids' => $turn['evidence_ids'] ?? [],
                /*
                 * Bounded to the columns, because MariaDB strict mode rejects
                 * the WHOLE insert on overflow and the catch below would turn
                 * that into a silently unpersisted turn. prompt_version is
                 * ours (short by convention), but `model` echoes whatever the
                 * provider reports and an operator-configured name can be
                 * arbitrarily long.
                 */
                'prompt_version' => $turn['prompt_version'] === null ? null : mb_substr((string) $turn['prompt_version'], 0, 32),
                'model' => $turn['model'] === null ? null : mb_substr((string) $turn['model'], 0, 64),
                'provider' => $turn['provider'] === null ? null : mb_substr((string) $turn['provider'], 0, 32),
                'responded_at' => now(),
                'validation_result' => $turn['validation_result'],
                'validation_detail' => $turn['validation_detail'],
                'cost_usd' => $turn['cost_usd'],
                'latency_ms' => $turn['latency_ms'],
                'prompt_tokens' => $turn['prompt_tokens'],
                'completion_tokens' => $turn['completion_tokens'],
                'is_withheld' => false,
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $this->messagePayload($assistant);
        } catch (Throwable $exception) {
            Log::warning('advisor.assistant_message_not_persisted', [
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
            ]);

            return [
                'id' => 'ephemeral-'.Str::uuid(),
                'role' => 'assistant',
                'content' => (string) $turn['content'],
                'content_class' => '',
                'is_withheld' => false,
                'withheld_reason' => null,
                'model' => $turn['model'] ?? null,
                'evidence_count' => count((array) ($turn['evidence_ids'] ?? [])),
                'locale' => (string) $turn['locale'],
            ];
        }
    }

    /**
     * A single summary-row edit, through the SAME parsers the interview
     * itself uses (Phase 18).
     *
     * The amend endpoint used to store the posted text verbatim — and the
     * edit box is prefilled with the LOCALIZED display label, so a no-op
     * save wrote e.g. "دیناری عێراقی (IQD)" over the canonical 'IQD' and a
     * retyped "200 هەزار دۆلار" landed as a literal string the matcher read
     * as 200. Routing the edit through the real per-slot parsers makes the
     * words mean the same thing whether they are typed in the chat or in
     * the summary panel: budget keeps its unit rules (including the
     * documented "a currency named inside the budget answer wins"),
     * currency canonicalizes to its code or the honest unknown sentinel,
     * and an unparseable edit KEEPS the old value rather than storing
     * garbage — merge() never erases on a failed extraction. A blank value
     * still clears the slot for re-asking, exactly the old contract.
     *
     * The currency slot needs one guard for that keep-the-old-value rule
     * to actually hold: extractCurrency() resolves undetectable text to
     * the CURRENCY_UNKNOWN sentinel so the INTERVIEW can honestly move on
     * from an unanswerable question, but the sentinel is a non-empty
     * string merge() would happily store — so an edit typo ("IQDD") would
     * silently demote a known 'IQD' to 'unknown'. An edit only stores the
     * sentinel when there is no known currency to lose.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function amendSlot(array $criteria, string $slot, ?string $text): array
    {
        $trimmed = trim((string) $text);

        if ($trimmed === '') {
            $criteria[$slot] = null;

            return $criteria;
        }

        if ($slot === 'budget') {
            return $this->applyBudgetReply($criteria, $trimmed);
        }

        $extracted = $this->extract($slot, $trimmed);

        if ($slot === 'currency'
            && $extracted === self::CURRENCY_UNKNOWN
            && ($criteria['currency'] ?? null) !== null
        ) {
            return $criteria;
        }

        return $this->flow->merge($criteria, $slot, $extracted);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function mergeReply(array $criteria, ?string $answeringSlot, string $text): array
    {
        /*
         * Correction v5, BLOCKER 6: the budget slot no longer accepts a
         * bare magnitude. Its reply runs through `applyBudgetReply()`,
         * which expands explicit unit words, accepts literal amounts on
         * request, and parks anything ambiguous for a clarification turn
         * instead of merging a number whose meaning nobody stated.
         *
         * With no pending slot (advisory stage) there is nothing to merge
         * directly; the opportunistic pickups below still run, so stated
         * facts are never dropped.
         */
        if ($answeringSlot === 'budget') {
            $criteria = $this->applyBudgetReply($criteria, $text);
        } elseif ($answeringSlot !== null && $answeringSlot !== '') {
            $criteria = $this->flow->merge($criteria, $answeringSlot, $this->extract($answeringSlot, $text));
        }

        if (! isset($criteria['purpose'])) {
            $criteria = $this->flow->merge($criteria, 'purpose', $this->extractPurpose(mb_strtolower($text)));
        }

        /*
         * v5, BLOCKER 6: opportunistic budget pickup now demands an
         * EXPLICIT unit — a scale word (`هەزار`, `million`, `k`…) or a
         * currency word in the same breath. The v4 rule took any digit
         * from any answer, so "3 bedrooms" typed at the area question
         * became a budget of 3 and the magnitude heuristic then made it
         * 3,000. A number without a stated unit is not a budget; it is a
         * number.
         */
        if (! isset($criteria['budget']) && $answeringSlot !== 'budget') {
            $amount = $this->parseAmount($text);

            if ($amount !== null
                && ($amount['has_unit'] || AdvisorCurrency::detect($text)['currency'] !== null)) {
                $criteria = $this->flow->merge($criteria, 'budget', $amount['value']);
                unset($criteria[self::BUDGET_CLARIFY]);
            }
        }

        /*
         * Correction v4, BLOCKER 3: opportunistic currency pickup.
         *
         * "١٥٠ هەزار دۆلار" answers two slots at once, and asking "dollars
         * or dinars?" straight after somebody said "dollars" is the kind of
         * thing that makes people abandon an interview.
         *
         * Only an EXPLICIT statement is taken here — never the `unknown`
         * sentinel. Opportunistic pickup must be able to find nothing;
         * that is the difference between reading an answer and inventing
         * one, and inventing one is the entire defect this corrects.
         */
        if (! isset($criteria['currency'])) {
            $criteria = $this->flow->merge(
                $criteria,
                'currency',
                AdvisorCurrency::detect($text)['currency'],
            );
        }

        if (! isset($criteria['property_type'])) {
            $criteria = $this->flow->merge($criteria, 'property_type', $this->extractPropertyType(mb_strtolower($text)));
        }

        return $criteria;
    }

    private function extract(string $slot, string $text): mixed
    {
        $normalised = mb_strtolower(trim($text));

        return match ($slot) {
            'purpose' => $this->extractPurpose($normalised),
            // v5, BLOCKER 6: budget never comes through the generic path —
            // mergeReply routes it to applyBudgetReply(), which owns the
            // unit rules. This arm exists so a stray call cannot regress
            // to bare-number acceptance.
            'budget' => null,
            'currency' => $this->extractCurrency($text),
            'property_type' => $this->extractPropertyType($normalised) ?? (trim($text) === '' ? null : trim($text)),
            default => trim($text) === '' ? null : trim($text),
        };
    }

    private function extractPurpose(string $text): ?string
    {
        $investment = ['investment', 'invest', 'استثمار', 'للاستثمار', 'وەبەرهێنان', 'وەبەرهێنانی'];
        $residence = ['residence', 'living', 'home', 'سكن', 'للسكن', 'نیشتەجێبوون', 'ژیان'];

        foreach ($investment as $needle) {
            if (str_contains($text, $needle)) {
                return 'investment';
            }
        }

        foreach ($residence as $needle) {
            if (str_contains($text, $needle)) {
                return 'residence';
            }
        }

        return null;
    }

    private function extractPropertyType(string $text): ?string
    {
        $types = [
            'apartment' => ['apartment', 'flat', 'شقة', 'شقه', 'شوقە', 'شوقه'],
            'villa' => ['villa', 'فيلا', 'ڤێلا', 'ڤیلا'],
            'house' => ['house', 'منزل', 'بيت', 'ماڵ'],
            'land' => ['land', 'plot', 'أرض', 'ارض', 'زەوی'],
            'office' => ['office', 'مكتب', 'ئۆفیس', 'نووسینگە'],
            'shop' => ['shop', 'store', 'محل', 'دوکان'],
        ];

        foreach ($types as $value => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * The answer to the currency question (v4, BLOCKER 3).
     *
     * Differs from the opportunistic pickup in {@see mergeReply()} in one
     * way that matters: this is the reply to a question that was actually
     * ASKED, so "I do not know", "whatever", or an unrecognised third
     * currency has to resolve to something, or the person is stuck being
     * asked the same question forever.
     *
     * That something is the sentinel `unknown`. It satisfies the flow (the
     * slot is answered, the interview proceeds) and it is stored as NULL
     * (the lead says "not stated" rather than claiming a currency nobody
     * named). Ambiguity — both currencies in one sentence — deliberately
     * returns null instead, because that is the one case where asking
     * again is genuinely useful.
     */
    private function extractCurrency(string $text): ?string
    {
        $detection = AdvisorCurrency::detect($text);

        if ($detection['currency'] !== null) {
            return $detection['currency'];
        }

        if ($detection['ambiguous']) {
            return null;
        }

        return trim($text) === '' ? null : self::CURRENCY_UNKNOWN;
    }

    /**
     * The budget slot's reply, under the v5 BLOCKER 6 rules.
     *
     * Four outcomes, none of them a guess:
     *
     *   UNIT-WORDED  ("200 million", "١٥٠ هەزار") — the tested parser
     *   expands the stated scale and the exact result is merged.
     *
     *   LITERAL      ("exactly 500", "بەس ٥٠٠", "فقط 500") — the person
     *   insisted on the bare figure; it is merged untouched. This is the
     *   path by which an explicit 500 IQD remains 500 IQD.
     *
     *   BARE ≥ 1000 with a KNOWN currency — the digits carry their own
     *   scale and the unit question is already answered; merged as-is.
     *
     *   BARE otherwise — parked in the clarification state. The next
     *   turn re-asks the budget with combined amount-and-currency quick
     *   replies built from the parked figure, and nothing reaches the
     *   criteria until the person resolves it.
     *
     * A currency named INSIDE the budget answer always wins, even over an
     * earlier answer — "later answers overwrite earlier ones" is the
     * flow's own correction rule, and a person typing "٢٠٠ ملیۆن دینار"
     * after clicking dollars has corrected themselves.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function applyBudgetReply(array $criteria, string $text): array
    {
        $stated = AdvisorCurrency::detect($text)['currency'];

        if ($stated !== null) {
            $criteria['currency'] = $stated;
        }

        $amount = $this->parseAmount($text);

        if ($amount === null) {
            return $criteria;
        }

        $currencyKnown = AdvisorCurrency::storable(
            is_string($criteria['currency'] ?? null) ? $criteria['currency'] : null
        ) !== null;

        if ($amount['has_unit']
            || $amount['literal']
            || ((float) $amount['value'] >= 1000 && $currencyKnown)) {
            unset($criteria[self::BUDGET_CLARIFY]);

            return $this->flow->merge($criteria, 'budget', $amount['value']);
        }

        $criteria[self::BUDGET_CLARIFY] = $amount['value'];

        return $criteria;
    }

    /**
     * One number and its STATED scale, or null.
     *
     * This is the only place scale words become multiplication, and it
     * multiplies nothing it was not told: `has_unit` records whether a
     * scale word was actually present, and `literal` whether the person
     * marked the figure as exact — the two facts BLOCKER 6's rules turn
     * on. Digits are normalised across Arabic-Indic and Eastern
     * Arabic-Indic keyboards; thousands separators (`,` `٬`) and Unicode
     * spaces are stripped before matching.
     *
     * @return array{value: string, has_unit: bool, literal: bool}|null
     */
    private function parseAmount(string $text): ?array
    {
        $normalised = strtr(mb_strtolower($text), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $normalised = str_replace(['٬', ',', "\u{00A0}", "\u{202F}"], ['', '', ' ', ' '], $normalised);

        if (preg_match('/(\d+(?:\.\d+)?)\s*(k|m|thousand|million|ألف|الف|آلاف|هەزار|هزار|مليون|ملايين|ملیۆن|میلیۆن)?(?!\p{L})/u', $normalised, $matches) !== 1) {
            return null;
        }

        $value = (float) $matches[1];
        $unit = $matches[2] ?? '';
        $hasUnit = $unit !== '';

        if (in_array($unit, ['k', 'thousand', 'ألف', 'الف', 'آلاف', 'هەزار', 'هزار'], true)) {
            $value *= 1000;
        } elseif (in_array($unit, ['m', 'million', 'مليون', 'ملايين', 'ملیۆن', 'میلیۆن'], true)) {
            $value *= 1000000;
        }

        if ($value <= 0) {
            return null;
        }

        $rendered = abs($value - round($value)) < 0.000001
            ? (string) (int) round($value)
            : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return [
            'value' => $rendered,
            'has_unit' => $hasUnit,
            'literal' => $this->statesLiteralAmount($normalised),
        ];
    }

    /**
     * Whether the person marked the figure as exact — "exactly 500",
     * "بەس ٥٠٠", "فقط 500", "just 500". The escape hatch that keeps the
     * clarification from being a trap for somebody whose budget really
     * is a bare number.
     */
    private function statesLiteralAmount(string $normalised): bool
    {
        foreach (['exactly', 'exact', 'just ', 'فقط', 'بالضبط', 'بس ', 'بەس', 'تەنیا', 'تەنها'] as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isFinishCommand(string $text): bool
    {
        $normalised = mb_strtolower(trim($text));
        $normalised = trim(preg_replace('/[\s،,.!؟?]+/u', ' ', $normalised) ?? $normalised);

        return in_array($normalised, [
            'كافي اعرض النتائج',
            'اعرض النتائج',
            'كافي اعرض الخلاصة',
            'كافي',
            'خلص اعرض الخلاصة',
            'اعرض الخلاصة',
            'بەسە ئەنجامەکان پیشان بدە',
            'ئەنجامەکان پیشان بدە',
            'بەسە کورتەکە پیشان بدە',
            'بەسە',
            'کافییە',
            'show results',
            'finish and summarize',
            'finish',
            "that's enough",
            'show summary',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function markRemainingOptionalAsSkipped(array $criteria, string $locale): array
    {
        $skip = $this->language->skipValue($locale);

        foreach ($this->flow->slots() as $slot) {
            if ($slot['required'] || array_key_exists($slot['key'], $criteria)) {
                continue;
            }

            if (in_array($slot['key'], ['return_goal', 'risk'], true)
                && ($criteria['purpose'] ?? null) !== 'investment') {
                continue;
            }

            $criteria[$slot['key']] = $skip;
        }

        return $criteria;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    private function evidenceFor(array $matches): array
    {
        $evidence = [];

        foreach ($matches as $match) {
            $evidence[] = [
                'type' => 'project',
                'id' => $match['project']['id'] ?? null,
                'state' => 'published',
                'score' => $match['score'] ?? null,
                'price' => $match['project']['price'] ?? null,
            ];
        }

        return $evidence;
    }

    /** @param array<string, mixed> $criteria */
    private function questionFrom(array $criteria): string
    {
        $purpose = $criteria['purpose'] ?? 'residence';
        $budget = $criteria['budget'] ?? null;
        $type = $criteria['property_type'] ?? null;

        return trim(sprintf(
            'Recommend %s options for %s%s.',
            $type ?? 'property',
            $purpose,
            $budget === null ? '' : ' within a budget of '.$budget,
        ));
    }
}
