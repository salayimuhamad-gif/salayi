<?php

declare(strict_types=1);

namespace App\Modules\Content\Support;

use App\Modules\Localization\Support\SoraniText;

/**
 * Runtime terminology resolution (spec 7.5, Step 7 exit criterion:
 * "Admin updates all visible terminology without code").
 *
 * The exit criterion is a claim about the whole product, so this resolver sits
 * between the translation files and every rendered string. Precedence:
 *
 *   1. An admin-approved glossary override for this locale.
 *   2. The published translation.
 *   3. The lang-file default.
 *   4. The key itself.
 *
 * Falling through to the key rather than to English is the Step 1 decision
 * carried forward: a missing Sorani term must be a visible defect that
 * lang-parity catches, not silent English on a Sorani page.
 *
 * `blocked_alternatives` is enforced here, not merely stored. Spec 7.5 gives an
 * administrator the ability to rule a word out, and a blocked term reaching a
 * page anyway would make that control decorative.
 */
final class TerminologyResolver
{
    /**
     * @param  array<string, array{
     *     term: string, status?: string,
     *     blocked_alternatives?: list<string>, approved_alternatives?: list<string>
     * }>  $glossary  keyed by term key
     * @param  array<string, string>  $translations  published translations, keyed by key
     * @param  array<string, string>  $defaults  lang-file values, keyed by key
     */
    public function __construct(
        private readonly array $glossary = [],
        private readonly array $translations = [],
        private readonly array $defaults = [],
    ) {}

    /**
     * @return array{value: string, source: string, is_fallback: bool}
     */
    public function resolve(string $key): array
    {
        $entry = $this->glossary[$key] ?? null;

        // Only an APPROVED glossary term overrides. A draft or AI-suggested one
        // must not reach a page (spec 7.4: AI never auto-publishes).
        if ($entry !== null && ($entry['status'] ?? 'draft') === 'approved' && trim($entry['term']) !== '') {
            return ['value' => $entry['term'], 'source' => 'glossary', 'is_fallback' => false];
        }

        if (isset($this->translations[$key]) && trim($this->translations[$key]) !== '') {
            return ['value' => $this->translations[$key], 'source' => 'translation', 'is_fallback' => false];
        }

        if (isset($this->defaults[$key]) && trim($this->defaults[$key]) !== '') {
            return ['value' => $this->defaults[$key], 'source' => 'lang_file', 'is_fallback' => false];
        }

        // The key itself, visibly wrong, so it gets fixed.
        return ['value' => $key, 'source' => 'key', 'is_fallback' => true];
    }

    public function get(string $key): string
    {
        return $this->resolve($key)['value'];
    }

    /**
     * Check a piece of admin-authored copy against the glossary's blocked list.
     *
     * Comparison uses the Step 1 Sorani search key, so a blocked term written
     * with an Arabic yeh or an extra diacritic is still caught — otherwise the
     * control would be trivially evaded by a typist who was not even trying to
     * evade it.
     *
     * @return array{clean: bool, blocked_found: list<array{key: string, term: string, prefer: string}>}
     */
    public function reviewCopy(string $text): array
    {
        $haystack = SoraniText::searchKey($text);
        $found = [];

        foreach ($this->glossary as $key => $entry) {
            foreach ($entry['blocked_alternatives'] ?? [] as $blocked) {
                $needle = SoraniText::searchKey($blocked);

                if ($needle === '' || ! str_contains($haystack, $needle)) {
                    continue;
                }

                $found[] = [
                    'key' => $key,
                    'term' => $blocked,
                    'prefer' => $entry['term'],
                ];
            }
        }

        return ['clean' => $found === [], 'blocked_found' => $found];
    }

    /**
     * Keys that would render as themselves — the terminology backlog.
     *
     * @param  list<string>  $requiredKeys
     * @return list<string>
     */
    public function missingKeys(array $requiredKeys): array
    {
        $missing = [];

        foreach ($requiredKeys as $key) {
            if ($this->resolve($key)['is_fallback']) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Proportion of required terminology an administrator has actually
     * customised, for the Step 7 exit-criterion dashboard.
     *
     * @param  list<string>  $requiredKeys
     * @return array{total: int, resolved: int, from_glossary: int, missing: int, coverage_percent: int}
     */
    public function coverage(array $requiredKeys): array
    {
        $resolved = 0;
        $fromGlossary = 0;

        foreach ($requiredKeys as $key) {
            $result = $this->resolve($key);

            if (! $result['is_fallback']) {
                $resolved++;
            }

            if ($result['source'] === 'glossary') {
                $fromGlossary++;
            }
        }

        $total = count($requiredKeys);

        return [
            'total' => $total,
            'resolved' => $resolved,
            'from_glossary' => $fromGlossary,
            'missing' => $total - $resolved,
            'coverage_percent' => $total === 0 ? 100 : (int) round($resolved / $total * 100),
        ];
    }
}
