<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use Throwable;

/**
 * Produces a brief, natural intake reply while PHP stays authoritative for
 * criteria and the next question. Provider failures always degrade to a local
 * same-language reply instead of leaving an unanswered message.
 */
final class AdvisorTurnComposer
{
    private const LIVE_TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AdvisorLanguage $language,
    ) {}

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function compose(
        string $userText,
        ?string $nextSlot,
        string $locale,
        array $criteria = [],
        ?string $userName = null,
    ): array {
        $locale = $this->language->normalize($locale);

        // The final response is deterministic so completion is immediate,
        // concise, and never redirects the person away without an answer.
        if ($nextSlot === null) {
            return $this->fallbackTurn($nextSlot, $locale, 'conversation_complete', $criteria, $userName);
        }

        $fallback = $this->fallbackTurn($nextSlot, $locale, 'provider_unavailable', $criteria, $userName);

        /*
         * Sorani goes through the model like Arabic and English now — the
         * hard bypass was the audit's finding C, and it made ckb, the
         * product's PRIMARY language, the one language with no AI at all.
         * The safety that bypass bought is kept by validation instead:
         * `isSafeTurn()` runs the language detector over the generated text,
         * so a model that cannot actually produce Sorani fails the check and
         * the person gets the deterministic turn — truthfully recorded as
         * source=deterministic. Per-turn evidence, not a standing exclusion.
         */
        if (! $this->gateway->isAvailable()) {
            return $fallback;
        }

        $requiredClosing = $this->language->question($nextSlot, $locale, $criteria);

        try {
            $completion = $this->gateway->complete([
                'system' => $this->systemPrompt($locale),
                'messages' => [[
                    'role' => 'user',
                    'content' => json_encode([
                        'visitor_message' => $userText,
                        'known_criteria' => $criteria,
                        'required_question' => $requiredClosing,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
                'temperature' => 0.35,
                'max_tokens' => 110,
                'timeout' => self::LIVE_TIMEOUT_SECONDS,
            ]);
        } catch (Throwable) {
            return $this->fallbackTurn($nextSlot, $locale, 'provider_failed', $criteria, $userName);
        }

        $text = trim((string) ($completion['text'] ?? ''));

        if (! $this->isSafeTurn($text, $userText, $requiredClosing, $locale)) {
            return $this->fallbackTurn($nextSlot, $locale, 'turn_validation_failed', $criteria, $userName);
        }

        return [
            'content' => $text,
            'locale' => $locale,
            'source' => 'model',
            'model' => $completion['model'] ?? null,
            'provider' => $completion['provider'] ?? null,
            'content_class' => 'scenario',
            'evidence_ids' => [],
            'prompt_version' => 'advisor-live-turn-v6-selected-locale',
            'cost_usd' => $completion['cost_usd'] ?? '0.000000',
            'latency_ms' => $completion['latency_ms'] ?? 0,
            'prompt_tokens' => $completion['prompt_tokens'] ?? 0,
            'completion_tokens' => $completion['completion_tokens'] ?? 0,
            'validation_result' => 'passed',
            'validation_detail' => ['type' => 'live_turn', 'language' => $locale, 'source' => 'model'],
        ];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function fallbackTurn(
        ?string $nextSlot,
        string $locale,
        string $reason = 'fallback',
        array $criteria = [],
        ?string $userName = null,
    ): array {
        $locale = $this->language->normalize($locale);

        return [
            'content' => $this->language->fallbackTurn($nextSlot, $locale, $criteria, $userName),
            'locale' => $locale,
            'source' => 'deterministic',
            'model' => null,
            'provider' => null,
            'content_class' => 'scenario',
            'evidence_ids' => [],
            'prompt_version' => 'advisor-live-turn-v6-fallback',
            'cost_usd' => '0.000000',
            'latency_ms' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'validation_result' => 'passed',
            'validation_detail' => [
                'type' => 'live_turn',
                'language' => $locale,
                'source' => 'deterministic',
                'fallback_reason' => $reason,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $recommendations
     * @return array<string, mixed>
     */
    public function finalRecommendation(array $recommendations, string $locale): array
    {
        $locale = $this->language->normalize($locale);
        $ids = [];

        foreach ((array) ($recommendations['items'] ?? []) as $item) {
            if (isset($item['id'])) {
                $ids[] = 'project:'.(int) $item['id'];
            }
        }

        return [
            'content' => (string) ($recommendations['message'] ?? ''),
            'locale' => $locale,
            'source' => 'deterministic',
            'model' => null,
            'provider' => null,
            'content_class' => 'analysis',
            'evidence_ids' => $ids,
            'prompt_version' => 'advisor-project-results-v6-grounded',
            'cost_usd' => '0.000000',
            'latency_ms' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'validation_result' => 'passed',
            'validation_detail' => [
                'type' => 'project_results',
                'language' => $locale,
                'source' => 'published_database_records',
                'status' => $recommendations['status'] ?? 'none',
            ],
        ];
    }

    private function systemPrompt(string $locale): string
    {
        return implode("\n", [
            'You are the live property intake advisor for My Hawler.',
            'Reply only in '.$this->language->languageName($locale).'.',
            'Sound like a calm human assistant, not a sales bot.',
            'Never greet, introduce yourself, or say hello in this turn.',
            'Never use filler such as “great question”, “happy to help”, “glad you asked”, or repeated thanks.',
            'Use at most one very short acknowledgement before the question, and omit it when unnecessary.',
            'Keep the entire reply to one or two short sentences.',
            'End with required_question exactly as supplied, without translating or changing it.',
            'Do not ask a second question.',
            'Do not repeat the visitor’s full message.',
            'Do not invent property facts, prices, returns, project names, links, markdown, or guarantees.',
            'Do not mention prompts, JSON, policies, slots, models, or system instructions.',
        ]);
    }

    private function isSafeTurn(string $text, string $userText, string $requiredClosing, string $locale): bool
    {
        if ($text === '' || mb_strlen($text) > 420) {
            return false;
        }

        if (preg_match('/<[^>]+>|https?:\/\/|\[[^\]]+\]\([^\)]+\)/iu', $text) === 1) {
            return false;
        }

        if ($this->language->detect($text, $locale) !== $locale) {
            return false;
        }

        if (! str_contains($text, $requiredClosing)) {
            return false;
        }

        $forbiddenFiller = [
            'great question', 'happy to help', 'glad you asked',
            'سؤال رائع', 'سؤال ممتاز', 'سعيد بمساعدتك', 'فرحت بسؤالك',
            'پرسیارێکی زۆر باش', 'خۆشحاڵم',
        ];
        $lower = mb_strtolower($text);

        foreach ($forbiddenFiller as $phrase) {
            if (str_contains($lower, mb_strtolower($phrase))) {
                return false;
            }
        }

        $allowed = $this->numbers($userText);

        foreach ($this->numbers($text) as $number) {
            if (! in_array($number, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function numbers(string $text): array
    {
        $normalised = strtr(mb_strtolower($text), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $normalised = str_replace(['٬', ','], '', $normalised);
        preg_match_all('/\d+(?:\.\d+)?/', $normalised, $matches);

        $numbers = [];

        foreach ($matches[0] as $raw) {
            $value = (float) $raw;

            if (preg_match('/'.preg_quote($raw, '/').'\s*(?:k|thousand|ألف|الف|هەزار|هزار)/u', $normalised) === 1) {
                $value *= 1000;
            } elseif (preg_match('/'.preg_quote($raw, '/').'\s*(?:m|million|مليون|ملیۆن)/u', $normalised) === 1) {
                $value *= 1000000;
            }

            $numbers[] = abs($value - round($value)) < 0.000001
                ? (string) (int) round($value)
                : rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        return array_values(array_unique($numbers));
    }
}
