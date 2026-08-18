<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Exceptions\AiProviderException;
use App\Modules\Advisor\Support\NumericGuard;
use App\Modules\Advisor\Support\RetrievalGuard;

/**
 * Composes an advisor answer (File one §6.6–§6.9, §6.14; File two §9).
 *
 * This is the class that makes the AI safe to put in front of a buyer, and its
 * shape is dictated by one rule from both documents: **the model explains, it
 * does not decide.**
 *
 * The order is the enforcement:
 *
 *   1. RETRIEVE   — the retrieval guard admits only published, unexpired,
 *                   correctly-owned evidence. Nothing else is visible to the
 *                   model at all, so it cannot leak what it never received.
 *   2. RANK       — LifestyleMatcher scores deterministically in PHP.
 *   3. EXPLAIN    — the model is handed the finished ranking and asked for
 *                   prose. It cannot move a score.
 *   4. VALIDATE   — every number in that prose is checked against the evidence.
 *                   An ungrounded figure means the prose is discarded, not
 *                   published with a warning.
 *
 * Step 4 is the one that costs something. A model that produces a beautiful
 * Sorani paragraph containing one invented price has produced a liability, and
 * the correct response is to fall back to the deterministic summary rather than
 * to ship it with a disclaimer nobody reads.
 */
final class AdvisorAnswerComposer
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly RetrievalGuard $retrieval,
        private readonly NumericGuard $numeric,
    ) {}

    /**
     * Produce an answer for a ranked candidate set.
     *
     * @param  list<array<string, mixed>>  $matches  already scored by LifestyleMatcher
     * @param  list<array<string, mixed>>  $evidence  candidate retrieval items
     * @return array<string, mixed>
     */
    public function compose(
        array $matches,
        array $evidence,
        string $question,
        string $locale = 'ckb',
        ?int $userId = null,
    ): array {
        // 1. Retrieval guard first. Draft, expired, internal and other users'
        //    records never reach the prompt (§6.10).
        $filtered = $this->retrieval->filter($evidence, $userId);
        // RetrievalGuard::filter() always returns both lists.
        $admitted = $filtered['admitted'];

        $deterministic = $this->deterministicSummary($matches);

        /*
         * §6.14 and §9: when the model is unavailable the product still works.
         * The ranking is computed in PHP and does not need prose to be useful,
         * so an outage costs the explanation, not the answer.
         */
        if (! $this->gateway->isAvailable()) {
            return $this->fallback($deterministic, $matches, 'provider_unavailable');
        }

        /*
         * Insufficient admitted evidence is not a reason to improvise. §9
         * requires the advisor to say plainly that the data is not available,
         * and a model asked to explain a ranking with nothing to cite will
         * produce something fluent and unsupported.
         */
        // RetrievalGuard::filter() always reports sufficiency.
        if ($filtered['sufficient'] !== true) {
            return $this->fallback($deterministic, $matches, 'insufficient_evidence');
        }

        try {
            $completion = $this->gateway->complete([
                'system' => $this->systemPrompt($locale),
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->userPrompt($question, $deterministic, $admitted),
                ]],
                'temperature' => 0.2,
                'max_tokens' => 900,
            ]);
        } catch (AiProviderException $e) {
            return $this->fallback($deterministic, $matches, 'provider_failed');
        }

        // 4. Every number the model wrote must appear in the evidence (§6.9).
        $validation = $this->numeric->validate(
            $completion['text'],
            $this->evidenceValues($admitted, $matches),
        );

        // The grounding validator always reports a verdict.
        if ($validation['grounded'] !== true) {
            /*
             * Discarded, not published with a caveat. An invented price in
             * fluent Sorani is more dangerous than no prose at all, because it
             * reads as authoritative and the caveat does not travel when the
             * paragraph is screenshotted and forwarded.
             */
            return $this->fallback($deterministic, $matches, 'numeric_validation_failed', $validation);
        }

        return [
            'answer' => $completion['text'],
            'source' => 'model',
            'matches' => $matches,
            'deterministic_summary' => $deterministic,
            // §6.8: the audit trail that makes an answer reproducible.
            'evidence_ids' => $this->evidenceIds($admitted),
            'model' => $completion['model'],
            'provider' => $completion['provider'] ?? null,
            'fell_back_provider' => $completion['fell_back'] ?? false,
            'prompt_version' => $this->promptVersion($locale),
            'cost_usd' => $completion['cost_usd'],
            'latency_ms' => $completion['latency_ms'],
            'validation' => $validation,
        ];
    }

    /**
     * The answer without prose.
     *
     * Not an error state. The ranking, the component breakdown and the measured
     * distances are the substance; the paragraph is presentation. Naming the
     * reason lets the interface say "explanations are unavailable right now"
     * rather than implying the recommendations themselves are degraded.
     *
     * @param  array<string, mixed>|null  $validation
     * @param  list<array<string, mixed>>  $deterministic
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function fallback(array $deterministic, array $matches, string $reason, ?array $validation = null): array
    {
        return [
            'answer' => null,
            'source' => 'deterministic',
            'fallback_reason' => $reason,
            'matches' => $matches,
            'deterministic_summary' => $deterministic,
            'evidence_ids' => [],
            'model' => null,
            'provider' => null,
            'prompt_version' => null,
            'cost_usd' => '0.000000',
            'latency_ms' => 0,
            'validation' => $validation,
        ];
    }

    /**
     * The ranking restated as facts, with no interpretation.
     *
     * This is what the model is asked to describe and what is shown when it
     * cannot be. Because it is generated from the same arithmetic that produced
     * the score, it cannot disagree with the breakdown the user can expand.
     *
     * @param  list<array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    private function deterministicSummary(array $matches): array
    {
        $summary = [];

        foreach ($matches as $match) {
            $summary[] = [
                'project' => $match['project']['name'] ?? null,
                'score' => $match['score'] ?? null,
                'confidence' => $match['confidence'] ?? null,
                'disqualified' => $match['disqualified'] ?? false,
                // The strongest and weakest components, which is what a person
                // actually asks after seeing a number.
                'strengths' => $this->topComponents($match, true),
                'weaknesses' => $this->topComponents($match, false),
                'distances' => $match['distances'] ?? [],
            ];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $match
     * @return list<string>
     */
    private function topComponents(array $match, bool $strongest): array
    {
        $components = $match['components'] ?? [];

        if (! is_array($components) || $components === []) {
            return [];
        }

        uasort($components, static function (array $a, array $b) use ($strongest): int {
            return $strongest
                ? ($b['score'] ?? 0) <=> ($a['score'] ?? 0)
                : ($a['score'] ?? 0) <=> ($b['score'] ?? 0);
        });

        return array_slice(array_keys($components), 0, 2);
    }

    /**
     * Every number the model is permitted to use.
     *
     * @param  list<array<string, mixed>>  $admitted
     * @param  list<array<string, mixed>>  $matches
     * @return list<string>
     */
    private function evidenceValues(array $admitted, array $matches): array
    {
        $values = [];

        foreach ($admitted as $item) {
            foreach (['price', 'value', 'distance_m', 'score'] as $field) {
                if (isset($item[$field])) {
                    $values[] = (string) $item[$field];
                }
            }
        }

        // The scores and measured distances are themselves evidence: they were
        // computed here, so the model quoting them back is grounded.
        foreach ($matches as $match) {
            if (isset($match['score'])) {
                $values[] = (string) $match['score'];
            }

            foreach ($match['distances'] ?? [] as $metres) {
                $values[] = (string) $metres;
                // Kilometres too, since §7 presents distance in km and the
                // model will naturally write "4.2" where evidence holds 4200.
                $values[] = number_format($metres / 1000, 1, '.', '');
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  list<array<string, mixed>>  $admitted
     * @return list<string>
     */
    private function evidenceIds(array $admitted): array
    {
        $ids = [];

        foreach ($admitted as $item) {
            $type = $item['type'] ?? 'unknown';
            $id = $item['id'] ?? null;

            if ($id !== null) {
                $ids[] = $type.':'.$id;
            }
        }

        return $ids;
    }

    private function promptVersion(string $locale): string
    {
        return 'advisor-explain-'.$locale.'-v1';
    }

    private function systemPrompt(string $locale): string
    {
        /*
         * The prompt forbids what the guards also catch. Belt and braces is
         * deliberate: the guard is what makes it safe, and the instruction is
         * what makes the guard rarely fire — a model told not to invent numbers
         * mostly does not, and every prevented violation is a request that did
         * not have to be discarded and retried.
         */
        return <<<PROMPT
        You explain a property ranking that has ALREADY been calculated.

        Rules, without exception:
        - You may not change, recalculate or dispute any score. They are final.
        - You may only state numbers that appear in the data you were given.
          Never estimate, round differently, or infer a figure.
        - If something was not provided, say plainly that it is not available.
        - Mention risks and unknowns where the data shows them.
        - Do not invent project names, prices, distances or dates.
        - Write in the language of the request (locale: {$locale}), naturally
          and concisely.
        PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $summary
     * @param  list<array<string, mixed>>  $admitted
     */
    private function userPrompt(string $question, array $summary, array $admitted): string
    {
        return implode("\n\n", [
            'QUESTION: '.$question,
            'RANKING (final, do not alter): '.json_encode($summary, JSON_UNESCAPED_UNICODE),
            'EVIDENCE (the only facts you may cite): '.json_encode($admitted, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
