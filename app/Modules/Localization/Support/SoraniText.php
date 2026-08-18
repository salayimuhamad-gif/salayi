<?php

declare(strict_types=1);

namespace App\Modules\Localization\Support;

/**
 * Kurdish Sorani text handling (spec 7.2).
 *
 * Two deliberately different operations, because conflating them corrupts data:
 *
 *   normalize()  — canonical CLEANUP, safe to store and display. Fixes only
 *                  true equivalences: Arabic yeh/kaf typed instead of the
 *                  Kurdish letters, invisible control characters, stray
 *                  diacritics, whitespace. Never changes a letter that
 *                  carries meaning in Sorani.
 *
 *   searchKey()  — aggressive FOLDING for the search index only. Collapses
 *                  ه/ە, ۆ/و, ێ/ی, ڕ/ر, ڵ/ل and strips punctuation, so that
 *                  "شاری هەولێر" and "شارى هه‌ولير" reach the same key.
 *                  Never store this back over the source text.
 *
 * The distinction matters because ه (U+0647, the consonant "h") and ە
 * (U+06D5, the vowel "e") are different letters that Erbil typists routinely
 * interchange. Folding them is right for matching and wrong for storage — if
 * normalize() folded them, every "هەولێر" saved through the admin would come
 * back out spelled differently than it was typed.
 */
final class SoraniText
{
    /** Invisible characters that break matching but render as nothing. */
    private const INVISIBLE = [
        "\u{200B}" => '', // zero width space
        "\u{200C}" => '', // zero width non-joiner
        "\u{200D}" => '', // zero width joiner
        "\u{200E}" => '', // left-to-right mark
        "\u{200F}" => '', // right-to-left mark
        "\u{061C}" => '', // arabic letter mark
        "\u{FEFF}" => '', // byte order mark
        "\u{0640}" => '', // tatweel / kashida — decorative elongation only
    ];

    /** Arabic harakat and Quranic marks. Never semantic in Sorani. */
    private const DIACRITICS = [
        "\u{064B}" => '', "\u{064C}" => '', "\u{064D}" => '', "\u{064E}" => '',
        "\u{064F}" => '', "\u{0650}" => '', "\u{0651}" => '', "\u{0652}" => '',
        "\u{0653}" => '', "\u{0654}" => '', "\u{0655}" => '', "\u{0656}" => '',
        "\u{0657}" => '', "\u{0658}" => '', "\u{0670}" => '', "\u{06D6}" => '',
        "\u{06D7}" => '', "\u{06D8}" => '', "\u{06D9}" => '', "\u{06DA}" => '',
        "\u{06DB}" => '', "\u{06DC}" => '', "\u{06DF}" => '', "\u{06E0}" => '',
        "\u{06E1}" => '', "\u{06E2}" => '', "\u{06E3}" => '', "\u{06E4}" => '',
        "\u{06E7}" => '', "\u{06E8}" => '', "\u{06EA}" => '', "\u{06EB}" => '',
        "\u{06EC}" => '', "\u{06ED}" => '',
    ];

    /**
     * True equivalences. An Arabic keyboard produces the left form; Sorani
     * orthography wants the right form. No meaning is lost.
     */
    private const CANONICAL = [
        "\u{064A}" => "\u{06CC}", // ي arabic yeh      -> ی farsi yeh
        "\u{0649}" => "\u{06CC}", // ى alef maksura    -> ی
        "\u{06D2}" => "\u{06CC}", // ے yeh barree      -> ی
        "\u{0643}" => "\u{06A9}", // ك arabic kaf      -> ک keheh
        "\u{06AA}" => "\u{06A9}", // ڪ swash kaf       -> ک
        "\u{0623}" => "\u{0627}", // أ alef w/ hamza   -> ا
        "\u{0625}" => "\u{0627}", // إ                  -> ا
        "\u{0622}" => "\u{0627}", // آ                  -> ا
        "\u{0671}" => "\u{0627}", // ٱ                  -> ا
        "\u{06CD}" => "\u{06CC}", // ۍ                  -> ی
        // Presentation forms that leak in from PDF and image OCR.
        "\u{FEEF}" => "\u{0647}", "\u{FEF0}" => "\u{0647}",
        "\u{FED9}" => "\u{06A9}", "\u{FEDA}" => "\u{06A9}",
        "\u{FEDB}" => "\u{06A9}", "\u{FEDC}" => "\u{06A9}",
        "\u{FEF1}" => "\u{06CC}", "\u{FEF2}" => "\u{06CC}",
        "\u{FEF3}" => "\u{06CC}", "\u{FEF4}" => "\u{06CC}",
    ];

    /**
     * Search-only folding. Each of these DOES change meaning, which is why it
     * never touches stored text.
     */
    private const SEARCH_FOLD = [
        "\u{0647}" => "\u{06D5}", // ه heh    -> ە ae   (typists interchange these)
        "\u{0629}" => "\u{06D5}", // ة teh marbuta -> ە (arabic loanwords)
        "\u{06C6}" => "\u{0648}", // ۆ o      -> و
        "\u{06C7}" => "\u{0648}", // ۇ        -> و
        "\u{0624}" => "\u{0648}", // ؤ        -> و
        "\u{06CE}" => "\u{06CC}", // ێ e      -> ی
        "\u{0695}" => "\u{0631}", // ڕ rolled r -> ر
        "\u{06B5}" => "\u{0644}", // ڵ velar l  -> ل
        "\u{0626}" => '',         // ئ hamza carrier: silent, drop for matching
        "\u{0621}" => '',         // ء hamza
    ];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Canonical cleanup. Safe to persist. Idempotent.
     */
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = strtr($text, self::INVISIBLE);
        $text = strtr($text, self::DIACRITICS);
        $text = strtr($text, self::CANONICAL);

        // Arabic-script presentation ligatures for lam-alef.
        $text = strtr($text, [
            "\u{FEFB}" => "\u{0644}\u{0627}", "\u{FEFC}" => "\u{0644}\u{0627}",
            "\u{FEF5}" => "\u{0644}\u{0627}", "\u{FEF7}" => "\u{0644}\u{0627}",
            "\u{FEF9}" => "\u{0644}\u{0627}",
        ]);

        return self::collapseWhitespace($text);
    }

    /**
     * Aggressive fold for indexing and matching. Never persist over source.
     */
    public static function searchKey(?string $text): string
    {
        $text = self::normalize($text);

        if ($text === '') {
            return '';
        }

        $text = strtr($text, self::DIGITS);
        $text = strtr($text, self::SEARCH_FOLD);

        // Latin is lowercased so "Empire World" and "empire world" collide;
        // Arabic script has no case, so this is a no-op there.
        $text = mb_strtolower($text, 'UTF-8');

        // Keep letters, marks and digits from any script; everything else
        // becomes a separator. Hyphens and quotes in project names vary too
        // much between sources to be load-bearing.
        $text = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $text) ?? '';

        return self::collapseWhitespace($text);
    }

    /**
     * URL slug that keeps Arabic-script letters. Sorani URLs stay readable
     * (spec 31.2 localized URLs); the browser percent-encodes on the wire.
     */
    public static function slug(?string $text, string $separator = '-'): string
    {
        $text = self::normalize($text);

        if ($text === '') {
            return '';
        }

        $text = strtr($text, self::DIGITS);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', $separator, $text) ?? '';

        return trim($text, $separator);
    }

    /** Arabic-Indic and Extended Arabic-Indic digits to ASCII. */
    public static function digitsToLatin(?string $text): string
    {
        return $text === null ? '' : strtr($text, self::DIGITS);
    }

    /** ASCII digits to Arabic-Indic, for display where an admin asks for it. */
    public static function digitsToArabicIndic(?string $text): string
    {
        return $text === null ? '' : strtr($text, array_flip(array_slice(self::DIGITS, 0, 10)));
    }

    /** True when the string contains any Arabic-script letter. */
    public static function isArabicScript(?string $text): bool
    {
        return $text !== null && $text !== '' && preg_match('/\p{Arabic}/u', $text) === 1;
    }

    /**
     * Fuzzy equality for admin de-duplication warnings (spec 11.3
     * "identify duplicates"). Advisory only — it flags a candidate for a
     * human to confirm, it never merges records on its own.
     */
    public static function looksLikeSame(?string $a, ?string $b): bool
    {
        $ka = self::searchKey($a);
        $kb = self::searchKey($b);

        if ($ka === '' || $kb === '') {
            return false;
        }

        if ($ka === $kb) {
            return true;
        }

        // Levenshtein is byte-based, so compare on a codepoint-per-slot basis.
        $la = self::codepoints($ka);
        $lb = self::codepoints($kb);
        $maxLen = max(count($la), count($lb));

        if ($maxLen === 0 || abs(count($la) - count($lb)) > 2) {
            return false;
        }

        $distance = self::levenshteinCodepoints($la, $lb);

        // One edit per five characters, capped at three. Tight enough that
        // "هەولێر" and "دهۆک" never collide.
        return $distance <= min(3, max(1, intdiv($maxLen, 5)));
    }

    private static function collapseWhitespace(string $text): string
    {
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return list<string> */
    private static function codepoints(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function levenshteinCodepoints(array $a, array $b): int
    {
        $m = count($a);
        $n = count($b);

        if ($m === 0) {
            return $n;
        }

        if ($n === 0) {
            return $m;
        }

        $previous = range(0, $n);

        for ($i = 1; $i <= $m; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $n; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + $cost,
                );
            }

            $previous = $current;
        }

        return $previous[$n];
    }
}
