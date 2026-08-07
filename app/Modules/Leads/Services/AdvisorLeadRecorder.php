<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

use App\Modules\Advisor\Models\AdvisorConversation;
use App\Modules\Advisor\Support\AdvisorCurrency;
use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\User;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One conversation, one lead (spec §7.1).
 *
 * The idempotency is the DATABASE'S: `demand_profiles.advisor_conversation_id`
 * is UNIQUE, and this service writes through an upsert keyed on it inside a
 * transaction. A retried submission, a double-clicked button and a replayed
 * request all land on the same row — duplication is a constraint violation,
 * not a code path.
 *
 * The mapping is deliberately narrow. Every column below accepts only the
 * vocabulary the interview itself can produce (`investment`/`residence`;
 * `apartment`/`villa`/`house`; a positive number; an area the geography table
 * actually contains) and everything else becomes NULL. A free-text answer the
 * extractor could not classify is a thing the person SAID, not a thing the
 * system KNOWS — it survives in the transcript and the localized summary, and
 * it does not get promoted into a structured field an admin will filter on.
 */
final class AdvisorLeadRecorder
{
    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<int, string>  $summaryLines
     */
    /**
     * @param  array<string, mixed>  $criteria
     * @param  list<string>  $summaryLines
     * @param  array{ip_hash?: string|null, user_agent_hash?: string|null, conversation_public_id?: string, policy_version?: string}  $consent
     *                                                                                                                                          Correction H6: consent context travels INTO the transaction, so
     *                                                                                                                                          the consent row and the lead commit or roll back together.
     */
    public function record(
        AdvisorConversation $conversation,
        User $user,
        array $criteria,
        array $summaryLines,
        string $locale,
        array $consent = [],
    ): DemandProfile {
        /*
         * Correction H6, mapping: every approved structured field the
         * criteria actually CARRY, and null for everything they do not —
         * never inferred, never defaulted. budget_min, project_id and the
         * size bounds existed on demand_profiles all along; they were
         * simply never written.
         */
        $mapped = [
            'user_id' => $user->id,
            'preferred_locale' => $locale,
            'objective' => $this->objective($criteria),
            'property_type' => $this->propertyType($criteria),
            'budget_min' => $this->numeric($criteria, 'budget_min'),
            'budget_max' => $this->budgetMax($criteria),
            /*
             * Correction v4, BLOCKER 3. This line used to be the literal
             * string 'USD' on every lead ever recorded, regardless of what
             * the person said. `currency` is now the currency they STATED,
             * or NULL when they stated none this product supports, and
             * `currency_source` records which of those it was so no screen
             * has to guess whether a 'USD' is real.
             */
            'currency' => $this->currency($criteria),
            'currency_source' => $this->currencySource($criteria),
            'project_id' => $this->publishedProjectId($criteria),
            'area_id' => $this->areaId($criteria),
            'size_min_sqm' => $this->numeric($criteria, 'size_min_sqm'),
            'size_max_sqm' => $this->numeric($criteria, 'size_max_sqm'),
            'timeframe' => $this->clamped($criteria, 'timeframe', 24),
            'financing' => $this->financing($criteria),
            'risk_preference' => $this->preference($criteria, 'risk'),
            'return_preference' => $this->preference($criteria, 'return_goal'),
            'ai_summary' => implode("\n", $summaryLines) ?: null,
            'ai_summary_locale' => $locale,
            'source' => 'advisor',
        ];

        /*
         * Correction §6.5: idempotent under CONCURRENT submissions, not just
         * sequential ones. The old select-then-insert let two simultaneous
         * requests both observe "no row" and race the insert; one then died
         * on the unique key. Three layers now:
         *
         *   1. The locked select serialises against in-flight writers — a
         *      second submission parks on the winner's row until it commits.
         *   2. The UNIQUE key on advisor_conversation_id stays the ground
         *      truth for the window the select cannot see (both arrive
         *      before either row exists).
         *   3. Losing that window is CAUGHT, not fatal: InnoDB rolls back
         *      only the failed statement, the transaction stays alive, and
         *      the loser re-reads the winner's row under the lock and
         *      applies its update there — stage untouched, exactly as any
         *      resubmission would.
         *
         * The stage rule is enforced structurally in both paths: $mapped
         * never contains a stage, `new` is written only through the insert.
         *
         * And a FOURTH layer, put there by the two-process proof rather than
         * by foresight: when BOTH submissions arrive before either row
         * exists, both empty locked selects take compatible GAP locks on the
         * unique index, and the two inserts then deadlock against each
         * other's gap (MySQL 1213) — which is transaction-level, so the
         * duplicate-key catch below never sees it. The retry count hands
         * the deadlock victim a fresh transaction; on the second pass the
         * winner's row exists, the locked select finds it, and the loser
         * becomes an ordinary update. Three attempts is one more than the
         * two-party race needs.
         */
        return DB::transaction(function () use ($conversation, $user, $locale, $consent, $mapped): DemandProfile {
            $existing = DemandProfile::query()
                ->where('advisor_conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $lead = $this->applyUpdate($existing, $mapped);
                $this->recordConsentOnce($user, $conversation, $locale, $consent);

                return $lead;
            }

            try {
                $lead = new DemandProfile;
                $lead->fill($mapped + ['stage' => 'new']);
                $lead->advisor_conversation_id = $conversation->id;
                $lead->save();
            } catch (UniqueConstraintViolationException) {
                $lead = $this->applyUpdate(
                    DemandProfile::query()
                        ->where('advisor_conversation_id', $conversation->id)
                        ->lockForUpdate()
                        ->firstOrFail(),
                    $mapped,
                );
            }

            /*
             * Correction H6: consent lives INSIDE this transaction, AFTER the
             * lead resolves. The ordering is the concurrency design: the lead
             * row lock above is the serialisation point, so by the time a
             * second submission reaches this line the first has committed its
             * consent and the locked re-check below sees it. And because it
             * is the same transaction, a lead failure means no consent and a
             * consent failure means no lead — the two commit together or not
             * at all, which is what "one submission" means.
             */
            $this->recordConsentOnce($user, $conversation, $locale, $consent);

            return $lead;
        }, attempts: 3);
    }

    /**
     * A resubmission updates the structured fields and NOTHING about the
     * pipeline: the sales team's stage survives, `new` is not among the
     * updatable keys, and the unique conversation key is reaffirmed rather
     * than moved.
     *
     * @param  array<string, mixed>  $mapped
     */
    private function applyUpdate(DemandProfile $lead, array $mapped): DemandProfile
    {
        $lead->fill($mapped);
        $lead->save();

        return $lead;
    }

    /**
     * The two values the interview's extractor can actually emit.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function objective(array $criteria): ?string
    {
        $value = $criteria['purpose'] ?? null;

        return in_array($value, ['investment', 'residence'], true) ? $value : null;
    }

    /** @param  array<string, mixed>  $criteria */
    private function propertyType(array $criteria): ?string
    {
        $value = $criteria['property_type'] ?? null;

        return in_array($value, ['apartment', 'villa', 'house'], true) ? $value : null;
    }

    /**
     * A stated budget is a ceiling, so it lands in budget_max — EXACTLY as
     * stated.
     *
     * Correction v5, BLOCKER 6. The line this replaces read every value
     * under 1000 as thousands, in every currency: an explicit `500 IQD`
     * became `500,000 IQD` on its way to the column, which is precisely
     * the magnitude-guessing the v4 report claimed was gone. The interview
     * now resolves units BEFORE anything reaches this class — unit words
     * are expanded by the tested amount parser, bare small numbers are
     * clarified with the person, and a value that arrives here is the
     * value they meant. This method's whole job is to not touch it.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function budget(array $criteria): ?float
    {
        $value = $criteria['budget'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount > 0 ? $amount : null;
    }

    /**
     * The stated currency, or null.
     *
     * `AdvisorCurrency::storable()` is the single gate: only USD and IQD
     * are ever written, the `unknown` sentinel resolves to null, and
     * anything else — a third currency, a typo, a value injected into the
     * criteria blob — lands as null rather than as itself. A column that
     * can only hold currencies the matcher understands is a column the
     * matcher can trust.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function currency(array $criteria): ?string
    {
        $value = $criteria['currency'] ?? null;

        return is_string($value) ? AdvisorCurrency::storable($value) : null;
    }

    /**
     * HOW the currency got here, for the screens that have to explain
     * themselves to a salesperson deciding whether to call somebody.
     *
     *   'stated'  — the person named it and the parser recognised it.
     *   'unknown' — the person was asked and named nothing usable.
     *   null      — no currency information passed through this interview
     *               at all (an older conversation replayed, for instance).
     *
     * The fourth value, 'legacy', is written only by the migration, onto
     * rows that predate this release. Nothing in application code produces
     * it, which is what makes it a reliable marker for "this row's USD was
     * an assumption, not an answer".
     *
     * @param  array<string, mixed>  $criteria
     */
    private function currencySource(array $criteria): ?string
    {
        $value = $criteria['currency'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return AdvisorCurrency::storable($value) === null ? 'unknown' : 'stated';
    }

    /**
     * The area only if the geography table can NAME it: a case-insensitive
     * match against the three name columns. A neighbourhood the platform does
     * not model stays null rather than becoming a wrong foreign key.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function areaId(array $criteria): ?int
    {
        $value = $criteria['area'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $needle = mb_strtolower(trim($value));

        $area = Area::query()
            ->where('publication_status', 'published')
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(name_ckb) = ?', [$needle])
                    ->orWhereRaw('LOWER(name_ar) = ?', [$needle])
                    ->orWhereRaw('LOWER(name_en) = ?', [$needle]);
            })
            ->first();

        return $area?->id;
    }

    /** @param  array<string, mixed>  $criteria */
    private function financing(array $criteria): ?string
    {
        $value = $criteria['payment'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $normalised = mb_strtolower($value);

        foreach (['instalment', 'installment', 'أقساط', 'اقساط', 'قیست', 'قست'] as $needle) {
            if (str_contains($normalised, $needle)) {
                return 'instalments';
            }
        }

        foreach (['cash', 'كاش', 'نقد', 'کاش'] as $needle) {
            if (str_contains($normalised, $needle)) {
                return 'cash';
            }
        }

        return null;
    }

    /**
     * Risk and return answers are free text; only the three-step vocabulary
     * is promoted into the 16-character preference columns.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function preference(array $criteria, string $key): ?string
    {
        $value = $criteria[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $normalised = mb_strtolower($value);

        foreach ([
            'low' => ['low', 'منخفض', 'قليل', 'کەم', 'نزم'],
            'medium' => ['medium', 'متوسط', 'مامناوەند', 'ناوەند'],
            'high' => ['high', 'عالي', 'مرتفع', 'بەرز', 'زۆر'],
        ] as $level => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalised, $needle)) {
                    return $level;
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $criteria */
    private function clamped(array $criteria, string $key, int $limit): ?string
    {
        $value = $criteria[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $limit);
    }

    /**
     * One ACTIVE contact consent per person — decided under the user row
     * lock, so two submissions (same conversation or different ones) cannot
     * both observe "none" and double-insert. Historical consent events are
     * untouched: a withdrawn consent stays withdrawn, a re-grant is a new
     * row, and nothing here turns the table into a click log.
     *
     * @param  array{ip_hash?: string|null, user_agent_hash?: string|null, conversation_public_id?: string, policy_version?: string}  $context
     */
    private function recordConsentOnce(User $user, AdvisorConversation $conversation, string $locale, array $context): void
    {
        if ($context === []) {
            return;
        }

        // The per-user mutex for consent decisions: cheaper than a schema
        // change, stronger than a gap lock on a non-unique index.
        User::query()->whereKey($user->id)->lockForUpdate()->first();

        $active = Consent::query()
            ->where('user_id', $user->id)
            ->where('type', 'company_contact')
            ->where('granted', true)
            ->whereNull('withdrawn_at')
            ->exists();

        if ($active) {
            return;
        }

        Consent::query()->create([
            'user_id' => $user->id,
            'type' => 'company_contact',
            'granted' => true,
            'source' => 'advisor_submit',
            'evidence' => [
                'conversation_id' => $context['conversation_public_id'] ?? $conversation->public_id,
                'policy_version' => $context['policy_version']
                    ?? (string) config('mulkihawler.registration.policy_version', '2026-08-01'),
                'method' => 'advisor_submit_button',
            ],
            'ip_hash' => $context['ip_hash'] ?? null,
            'user_agent_hash' => $context['user_agent_hash'] ?? null,
            'locale' => $locale,
            'granted_at' => now(),
        ]);
    }

    /**
     * budget_max: an explicit `budget_max` in the criteria wins; otherwise
     * the interview\'s single stated `budget` remains the ceiling it always
     * was. Nothing is invented for a person who named no number.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function budgetMax(array $criteria): ?float
    {
        return $this->numeric($criteria, 'budget_max') ?? $this->budget($criteria);
    }

    /**
     * A bounded positive numeric when the key is present and sane; null
     * otherwise. The clamp guards the column, not the person — nobody\'s
     * answer is "corrected", absurd input is simply not stored.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function numeric(array $criteria, string $key): ?float
    {
        $value = $criteria[$key] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return ($number > 0 && $number < 1_000_000_000_000) ? $number : null;
    }

    /**
     * A project reference is stored only when it names a PUBLISHED project —
     * the same visibility rule every public surface applies. An unknown or
     * unpublished id is null, never an error and never a guess.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function publishedProjectId(array $criteria): ?int
    {
        $value = $criteria['project_id'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $exists = Project::query()
            ->whereKey((int) $value)
            ->where('publication_status', PublicationStatus::Published)
            ->exists();

        return $exists ? (int) $value : null;
    }
}
