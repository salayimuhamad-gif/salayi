<?php

declare(strict_types=1);

namespace App\Modules\Localization\Support;

/**
 * Sorani search normalisation (File two §2).
 *
 * Kurdish Sorani is written in a modified Arabic script, and the same word is
 * routinely typed with different code points depending on the keyboard. An
 * Arabic keyboard produces ي and ك; a Kurdish one produces ی and ک. They look
 * nearly identical on screen and are different bytes, so `LIKE '%گەڕەک%'`
 * silently fails to match `گەڕەك` — the searcher sees an empty result and
 * concludes the platform has no data about their own neighbourhood.
 *
 * This is not a cosmetic concern for a Sorani-first product. It is the
 * difference between a search box that works for Erbil users and one that works
 * only for whoever typed the data in.
 *
 * The normalised form is what gets stored in a search column and what a query
 * is normalised to before matching — both sides, or the fix does nothing.
 *
 * Deliberately NOT handled here:
 *   - Stemming. Kurdish morphology is rich and a wrong stem produces confident
 *     wrong matches, which is worse than a missed one in a market-data product.
 *   - Transliteration between Arabic and Latin Kurdish. That is a different
 *     problem with different failure modes and belongs in its own layer.
 */
final class SoraniNormalizer
{
    /**
     * Character folds applied to every indexed and searched string.
     *
     * Each pair exists because a real keyboard produces the left-hand form
     * where Sorani orthography expects the right-hand one.
     */
    private const FOLDS = [
        // Arabic yeh / farsi yeh / alef maksura → Kurdish yeh.
        'ي' => 'ی',
        'ى' => 'ی',
        'ئ' => 'ئ',

        // Arabic kaf → Kurdish kaf. The single most common mismatch, because
        // ك sits on the default Arabic layout and ک does not.
        'ك' => 'ک',

        // Teh marbuta → heh. Arabic loanwords in Kurdish are frequently typed
        // with ة where Sorani writes ه.
        'ة' => 'ه',

        // Arabic heh variants.
        'ھ' => 'ه',
        'ہ' => 'ه',

        // Waw variants used for the Kurdish o/u vowels.
        'ؤ' => 'و',

        // Alef with hamza / madda → bare alef. A searcher almost never types
        // the diacritical form even when the stored text has it.
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
    ];

    /**
     * Arabic-Indic and extended Arabic-Indic digits → Latin.
     *
     * A price typed as ١٢٠ and a price stored as 120 are the same price. Both
     * ranges appear in practice: Arabic keyboards emit U+0660–0669 and Persian
     * ones U+06F0–06F9.
     */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Marks removed entirely rather than folded.
     *
     * Kurdish is normally written without short-vowel diacritics, but text
     * copied from religious or academic sources carries them. Stripping means
     * a pasted name still matches a typed one.
     *
     * Also removes the zero-width non-joiner, which is invisible, common in
     * Persian-influenced input, and otherwise breaks every comparison it
     * appears in.
     */
    private const STRIP = [
        "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}",
        "\u{0651}", "\u{0652}", "\u{0653}", "\u{0654}", "\u{0655}", "\u{0670}",
        "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}",
    ];

    /**
     * The canonical form for storage and comparison.
     *
     * Idempotent: normalising an already-normalised string returns it
     * unchanged, which matters because the same value passes through this on
     * write and again on every query.
     */
    public function normalize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = strtr($value, self::FOLDS);
        $value = strtr($value, self::DIGITS);
        $value = str_replace(self::STRIP, '', $value);

        // Case folding matters for the Latin text that appears alongside
        // Kurdish — project names, developer names, unit codes.
        $value = mb_strtolower($value, 'UTF-8');

        // Any run of whitespace collapses to one space, so "مفتی   ٢" and
        // "مفتی ٢" are the same query.
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * A LIKE pattern for the normalised column.
     *
     * The wildcards `%` and `_` are escaped so a visitor typing a percent sign
     * gets a literal search rather than a full-table match — which on a large
     * places table is both a wrong answer and a slow one.
     */
    public function likePattern(?string $value): string
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return '%';
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);

        return '%'.$escaped.'%';
    }

    /**
     * Split a query into normalised terms.
     *
     * Useful for AND-matching several words against one column, which is how a
     * person searching "ronaki apartment" expects to be treated — they are
     * describing one thing with two words, not asking for either.
     *
     * @return list<string>
     */
    public function terms(?string $value): array
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(
            explode(' ', $normalized),
            // Single characters match almost everything and cost a full scan
            // to discover it.
            static fn (string $term): bool => mb_strlen($term) > 1,
        ));
    }

    /**
     * Do two strings mean the same thing once normalised?
     *
     * Used for duplicate detection on import, where the same area arriving as
     * "گەڕەکی ڕۆناکی" and "گەڕەكی ڕۆناكی" must be recognised as one place
     * rather than created twice.
     */
    public function equivalent(?string $a, ?string $b): bool
    {
        return $this->normalize($a) === $this->normalize($b) && $this->normalize($a) !== '';
    }
}
