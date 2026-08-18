<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Support;

/**
 * The currency a person actually STATED (correction v4, BLOCKER 3).
 *
 * The defect this exists to end: `AdvisorLeadRecorder` wrote the literal
 * string `'USD'` on every lead. Somebody who said "٢٠٠ ملیۆن دینار" — two
 * hundred million Iraqi dinars, roughly 150,000 dollars — reached the sales
 * team as a two-hundred-million-DOLLAR buyer. That is not a rounding error
 * or a display bug; it is a different person, and every downstream
 * decision made about them was made about somebody who does not exist.
 *
 * The design rule, and the only one that matters here: THIS CLASS NEVER
 * GUESSES. It recognises explicit currency words in the three languages
 * the product speaks, and it returns null for everything else. Null is not
 * a failure — it is the honest answer "they did not say", and it is what
 * makes the interview ask instead of assume.
 *
 * Two things it deliberately does NOT do:
 *
 *   - It does not infer from magnitude. "200,000,000" looks like dinars and
 *     "150,000" looks like dollars, and building that in would be exactly
 *     the same class of assumption that caused the bug, just with better
 *     manners. A big number in dollars is a real customer.
 *   - It does not convert. Conversion needs a dated rate from a recorded
 *     source, and a lead that silently changed units is unauditable. If
 *     conversion is ever added it belongs somewhere that can show its
 *     working, not here.
 *
 * Ambiguity is reported rather than resolved: a sentence naming BOTH
 * currencies returns `ambiguous`, and the caller asks.
 */
final class AdvisorCurrency
{
    public const USD = 'USD';

    public const IQD = 'IQD';

    /** The only currencies the advisor may store. */
    public const SUPPORTED = [self::USD, self::IQD];

    /**
     * Dollar markers across ckb / ar / en.
     *
     * `$` is included but `د.ع` is handled in the dinar list; both are
     * matched (see mentions() for the per-script rule), so ordering never
     * matters — a text containing both is ambiguous by construction.
     */
    private const USD_TOKENS = [
        'usd', 'us dollar', 'dollar', 'dollars', '$',
        'دولار', 'دولارات', 'بالدولار', 'الدولار',
        'دۆلار', 'دۆلاری', 'بەدۆلار',
    ];

    /**
     * Dinar markers across ckb / ar / en.
     *
     * "dinar" alone counts: within this product's geography an unqualified
     * dinar is the Iraqi one, and refusing to read it would push people
     * into the clarification question for no gain. Anything genuinely
     * foreign ("Jordanian dinar", "دينار أردني") is caught by
     * {@see mentionsUnsupportedCurrency()} and refused rather than
     * mis-stored.
     */
    private const IQD_TOKENS = [
        'iqd', 'iraqi dinar', 'dinar', 'dinars',
        'دينار', 'دنانير', 'بالدينار', 'الدينار', 'د.ع',
        'دینار', 'دیناری', 'دیناری عێراقی', 'بەدینار',
    ];

    /**
     * Currencies the platform prices in elsewhere, or that a person might
     * plausibly name, which this flow must NOT quietly store as USD.
     *
     * EUR appears in `config('mulkihawler.pricing.supported_currencies')`,
     * so somebody may well say it. The advisor still cannot match on it —
     * no project is priced in euro — so naming it is treated as
     * "unsupported", which is a refusal the person can see, not a silent
     * downgrade.
     */
    /*
     * v5, BLOCKER 5: the bare token `try` is deliberately ABSENT. As an
     * ISO code it collides head-on with the English verb — "try another
     * property" is a sentence this product hears every day, and no
     * boundary rule can tell that `try` from the lira's. A person who
     * genuinely means Turkish lira says `lira`/`ليرة`/`لیرە` or
     * `turkish lira`, all of which are here. Dropping the one
     * irreducibly ambiguous token is the honest fix; context-guessing
     * around it would be the same class of inference BLOCKER 3 removed.
     */
    private const UNSUPPORTED_TOKENS = [
        'eur', 'euro', 'euros', 'يورو', 'اليورو', 'یۆرۆ',
        'gbp', 'pound', 'sterling', 'جنيه', 'إسترليني', 'پاوەند',
        'lira', 'turkish lira', 'ليرة', 'لیرە',
        'aed', 'dirham', 'درهم', 'دیرهەم',
        'jod', 'jordanian', 'أردني', 'اردني', 'ئوردنی',
        'kwd', 'kuwaiti', 'كويتي', 'کوەیتی',
    ];

    /**
     * What a piece of text says about currency.
     *
     * @return array{currency: string|null, ambiguous: bool, unsupported: bool}
     */
    public static function detect(?string $text): array
    {
        $none = ['currency' => null, 'ambiguous' => false, 'unsupported' => false];

        if ($text === null) {
            return $none;
        }

        $haystack = self::normalise($text);

        if ($haystack === '') {
            return $none;
        }

        $usd = self::mentions($haystack, self::USD_TOKENS);
        $iqd = self::mentions($haystack, self::IQD_TOKENS);

        /*
         * Both named in one breath ("is that dollars or dinars?" typed back
         * at us, or "٥٠ هەزار دۆلار یان ١٠٠ ملیۆن دینار"). There is no
         * defensible way to pick, so the caller asks.
         */
        if ($usd && $iqd) {
            return ['currency' => null, 'ambiguous' => true, 'unsupported' => false];
        }

        if ($usd) {
            return ['currency' => self::USD, 'ambiguous' => false, 'unsupported' => false];
        }

        if ($iqd) {
            /*
             * A qualified foreign dinar is NOT the Iraqi one. Checked only
             * on the dinar branch, so "Jordanian" in an unrelated sentence
             * about a dollar budget cannot poison a clear answer.
             */
            return self::mentions($haystack, ['أردني', 'اردني', 'jordanian', 'ئوردنی', 'كويتي', 'kuwaiti', 'کوەیتی'])
                ? ['currency' => null, 'ambiguous' => false, 'unsupported' => true]
                : ['currency' => self::IQD, 'ambiguous' => false, 'unsupported' => false];
        }

        if (self::mentions($haystack, self::UNSUPPORTED_TOKENS)) {
            return ['currency' => null, 'ambiguous' => false, 'unsupported' => true];
        }

        return $none;
    }

    /**
     * The stored value for a detection, or null when nothing may be stored.
     *
     * The one place a currency becomes a column value, so the "only
     * SUPPORTED gets written" rule has exactly one enforcement point.
     */
    public static function storable(?string $candidate): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $upper = strtoupper(trim($candidate));

        return in_array($upper, self::SUPPORTED, true) ? $upper : null;
    }

    /**
     * Whether two currencies may be compared as amounts.
     *
     * Used by the matcher. Unknown on either side is NOT comparable — an
     * unknown budget against a USD price is precisely the comparison that
     * produced the original defect.
     */
    public static function comparable(?string $left, ?string $right): bool
    {
        $left = self::storable($left);
        $right = self::storable($right);

        return $left !== null && $right !== null && $left === $right;
    }

    /**
     * Whether the haystack states one of the tokens.
     *
     * Correction v5, BLOCKER 5. `str_contains` for every token meant
     * `ordinary` contained `dinar`, `neuron` contained `euro`, and a
     * person describing an "extraordinary investment" was recorded as
     * having named the Iraqi dinar. Two matching rules now, chosen per
     * token by its script:
     *
     *   LATIN tokens (pure a–z words and phrases, incl. ISO codes) match
     *   only as complete letter-runs: not preceded or followed by another
     *   Unicode letter. Digits and punctuation still count as boundaries,
     *   so `150k dollars`, `USD500` and `dinar.` all read naturally while
     *   `ordinary` and `neuron` no longer say anything about money.
     *
     *   ARABIC-SCRIPT tokens keep substring matching ON PURPOSE. Arabic
     *   and Sorani attach articles and prepositions to the word itself —
     *   `والدولار`, `بالدینارە` — and a letter-boundary rule would refuse
     *   exactly the forms people type. The token lists carry no
     *   Arabic-script fragment short enough to hide inside an unrelated
     *   word, which is what makes this safe where it was not for Latin.
     *
     *   Tokens containing non-letters (`$`, `د.ع`) also stay substring:
     *   a boundary test on `$` would refuse `US$100`.
     *
     * @param  list<string>  $tokens
     */
    private static function mentions(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            $needle = self::normalise($token);

            if ($needle === '') {
                continue;
            }

            if (self::isLatinWordToken($needle)) {
                $pattern = '/(?<!\p{L})'.preg_quote($needle, '/').'(?!\p{L})/u';

                if (preg_match($pattern, $haystack) === 1) {
                    return true;
                }

                continue;
            }

            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A token that is entirely Latin letters (spaces allowed for
     * phrases). Only these get the letter-boundary rule; see
     * {@see mentions()} for why the others must not.
     */
    private static function isLatinWordToken(string $token): bool
    {
        return preg_match('/^[a-z]+(?: [a-z]+)*$/', $token) === 1;
    }

    /**
     * Lower-cased, with Arabic/Persian presentation differences folded so
     * the same word typed on a Kurdish and an Arabic keyboard matches the
     * same token.
     *
     * The pairs that actually collide in this vocabulary: ی/ي (Farsi vs
     * Arabic yeh), ک/ك (keheh vs kaf), ە/ه (ae vs heh), ۆ/و. Folding them
     * means the token lists do not have to carry every spelling of every
     * word, which is where this kind of matcher usually rots.
     */
    private static function normalise(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return strtr($lower, [
            'ي' => 'ی',
            'ك' => 'ک',
            'ه' => 'ە',
            'ۆ' => 'و',
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ة' => 'ە',
            // v5: the raw invisible ZWNJ literal duplicated the explicit
            // escape below — one key, written twice. The escapes stay:
            // they are the only spelling a reader can see.
            "\u{200c}" => '',
            "\u{200f}" => '',
            "\u{200e}" => '',
        ]);
    }
}
