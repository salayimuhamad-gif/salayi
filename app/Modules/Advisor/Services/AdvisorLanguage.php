<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Support\AdvisorCurrency;
use Illuminate\Support\Facades\Lang;

/**
 * Owns advisor locale selection and all user-facing labels.
 *
 * The selected site locale is authoritative. Language detection is retained as
 * a fallback for older callers, but the live controller passes the UI locale so
 * a short Sorani answer such as "سلاو" can never flip an open Kurdish chat to
 * Arabic.
 */
final class AdvisorLanguage
{
    /** @var list<string> */
    private const SUPPORTED = ['ckb', 'ar', 'en'];

    public function normalize(?string $locale, string $fallback = 'ckb'): string
    {
        $fallback = in_array($fallback, self::SUPPORTED, true) ? $fallback : 'ckb';
        $candidate = mb_strtolower(trim((string) $locale));

        if (str_starts_with($candidate, 'ckb') || $candidate === 'ku') {
            return 'ckb';
        }

        if (str_starts_with($candidate, 'ar')) {
            return 'ar';
        }

        if (str_starts_with($candidate, 'en')) {
            return 'en';
        }

        return $fallback;
    }

    public function detect(string $text, string $fallback = 'ckb'): string
    {
        $fallback = $this->normalize($fallback);
        $trimmed = trim($text);

        if ($trimmed === '') {
            return $fallback;
        }

        if (preg_match('/[A-Za-z]/', $trimmed) === 1
            && preg_match('/\p{Arabic}/u', $trimmed) !== 1) {
            $latin = mb_strtolower($trimmed);
            $latinSorani = [
                'slaw', 'sllaw', 'silaw', 'choni', 'chony', 'hawler', 'kurdish',
                'budja', 'budget', 'dawet', 'dawe', 'shuqe', 'shwqa', 'xanu',
                'bale', 'naxer', 'naxêr',
            ];

            return $this->wordScore($latin, $latinSorani) > 0 ? 'ckb' : 'en';
        }

        if (preg_match('/\p{Arabic}/u', $trimmed) === 1) {
            if (preg_match('/[ەڕڵۆێڤ]/u', $trimmed) === 1) {
                return 'ckb';
            }

            $normalised = mb_strtolower($trimmed);
            $soraniWords = [
                'بودجە', 'دۆلارە', 'دەوێ', 'بۆ', 'لە', 'هەولێر', 'شوقە',
                'خانوو', 'کڕین', 'فرۆشتن', 'وەبەرهێنان', 'نیشتەجێبوون',
                'دەتەوێ', 'پارەدان', 'ناوچە', 'نزیک', 'بەڵێ', 'نەخێر',
                'بەسە', 'کافییە', 'ماڵ', 'زەوی', 'قیست',
            ];
            $arabicWords = [
                'ميزاني', 'ميزانية', 'دولار', 'اريد', 'أريد', 'شقة', 'عقار',
                'للسكن', 'للاستثمار', 'استثمار', 'بيت', 'منزل', 'فيلا', 'محل',
                'السلام', 'عليكم', 'مرحبا', 'اهلا', 'أهلا', 'شكرا', 'كيف',
                'شلون', 'وين', 'أربيل', 'اربيل', 'تقريب', 'دفع', 'قسط',
                'نعم', 'لا', 'كافي', 'خلص',
            ];

            $soraniScore = $this->wordScore($normalised, $soraniWords);
            $arabicScore = $this->wordScore($normalised, $arabicWords);

            if ($arabicScore > $soraniScore) {
                return 'ar';
            }

            if ($soraniScore > 0) {
                return 'ckb';
            }

            // Short Arabic-script words are ambiguous. Keep the selected UI
            // locale instead of guessing and changing the language mid-chat.
            return in_array($fallback, ['ckb', 'ar'], true) ? $fallback : 'ar';
        }

        return $fallback;
    }

    /** @param list<string> $needles */
    private function wordScore(string $text, array $needles): int
    {
        $score = 0;

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $score++;
            }
        }

        return $score;
    }

    public function initialPrompt(?string $slot, string $locale, ?string $userName = null): string
    {
        $locale = $this->normalize($locale);

        if ($slot === null) {
            return $this->completeMessage($locale, [], $userName);
        }

        $name = $this->safeName($userName);
        $welcome = (string) Lang::get('advisor.chat.live.welcome', [
            'name' => $name === null ? '' : ' '.$name,
        ], $locale);

        return trim($welcome.' '.$this->question($slot, $locale));
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    public function question(string $slot, string $locale, array $criteria = []): string
    {
        $locale = $this->normalize($locale);

        /*
         * v5, BLOCKER 6: the budget question knows what it is asking
         * about. With a parked bare figure it asks the clarification —
         * "150 what?" — with that figure in the sentence; with a known
         * currency it asks in that currency. Each variant is an ordinary
         * lang key, so translators own the words and a missing variant
         * falls back to the generic question rather than to English.
         */
        if ($slot === 'budget') {
            $pending = $criteria[AdvisorConversationService::BUDGET_CLARIFY] ?? null;

            if (is_scalar($pending) && (string) $pending !== '') {
                $clarify = $this->questionString('budget_clarify', $locale, ['amount' => (string) $pending]);

                if ($clarify !== null) {
                    return $clarify;
                }
            }

            $currency = AdvisorCurrency::storable(
                is_string($criteria['currency'] ?? null) ? $criteria['currency'] : null
            );

            if ($currency !== null) {
                $variant = $this->questionString('budget_'.strtolower($currency), $locale);

                if ($variant !== null) {
                    return $variant;
                }
            }
        }

        return $this->questionString($slot, $locale)
            ?? (string) Lang::get('advisor.chat.slots.'.$slot, [], $locale);
    }

    /**
     * A live-question string by key, or null when the key is untranslated
     * — never the raw key, never a silent English fallback.
     *
     * @param  array<string, string>  $replace
     */
    private function questionString(string $key, string $locale, array $replace = []): ?string
    {
        $full = 'advisor.chat.live.questions.'.$key;
        $question = Lang::get($full, $replace, $locale);

        return is_string($question) && $question !== $full ? $question : null;
    }

    /** @param array<string, mixed> $criteria */
    public function fallbackTurn(
        ?string $slot,
        string $locale,
        array $criteria = [],
        ?string $userName = null,
    ): string {
        $locale = $this->normalize($locale);

        if ($slot === null) {
            return $this->completeMessage($locale, $criteria, $userName);
        }

        return $this->question($slot, $locale, $criteria);
    }

    /** @param array<string, mixed> $criteria */
    public function completeMessage(string $locale, array $criteria = [], ?string $userName = null): string
    {
        $locale = $this->normalize($locale);
        $name = $this->safeName($userName);
        $summary = $this->summaryText($criteria, $locale);

        return match ($locale) {
            'ar' => trim(($name === null ? 'تمام.' : 'تمام '.$name.'.').' هذا أقرب تطابق لطلبك'.($summary === '' ? '.' : ': '.$summary.'.')),
            'en' => trim(($name === null ? 'Done.' : 'Done, '.$name.'.').' Here are the closest matches'.($summary === '' ? '.' : ' for '.$summary.'.')),
            default => trim(($name === null ? 'تەواو.' : 'تەواو '.$name.'.').' ئەمانە نزیکترین هەڵبژاردەن بۆ داواکارییەکەت'.($summary === '' ? '.' : ': '.$summary.'.')),
        };
    }

    /**
     * @return list<array{label: string, value: string, icon: string}>
     */
    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array{label: string, value: string, icon: string}>
     */
    public function quickReplies(?string $slot, string $locale, bool $canFinish = false, array $criteria = []): array
    {
        // No pending question means the ADVISORY stage, not the end: the
        // chips become optional accelerators for the follow-up conversation.
        // Each label round-trips through the same intent recognition the
        // typed path uses, so a chip keeps working when the model is off.
        if ($slot === null) {
            return $this->advisoryReplies($this->normalize($locale));
        }

        $locale = $this->normalize($locale);

        /*
         * v5, BLOCKER 6: the budget replies carry their unit ON the
         * button. Every value below round-trips through the same parser
         * and currency detector the typed path uses — "١٥٠ هەزار دۆلار"
         * IS the message the click sends — so a quick reply can never
         * mean something different from typing the same words, and no
         * translation table has to stay in sync with a value table.
         */
        if ($slot === 'budget') {
            return $this->budgetReplies($locale, $criteria, $canFinish);
        }

        $sets = match ($locale) {
            'ar' => [
                'purpose' => [['سكن', 'home'], ['استثمار', 'growth']],

                'currency' => [['دولار أمريكي', 'wallet'], ['دينار عراقي', 'wallet']],
                'property_type' => [['شقة', 'apartment'], ['فيلا', 'villa'], ['بيت', 'house'], ['أرض', 'land']],
                'area' => [['ما عندي منطقة محددة', 'location']],
                'workplace' => [['مو مهم', 'briefcase']],
                'schools' => [['نعم', 'check'], ['لا', 'close']],
                'family' => [['نعم', 'family'], ['لا', 'close']],
                'timeframe' => [['خلال 3 أشهر', 'clock'], ['خلال 6 أشهر', 'clock'], ['خلال سنة', 'calendar']],
                'payment' => [['نقد', 'cash'], ['أقساط', 'card'], ['الاثنين مناسب', 'split']],
                'risk' => [['منخفضة', 'shield'], ['متوسطة', 'balance'], ['عالية', 'bolt']],
                'return_goal' => [['دخل إيجار', 'key'], ['ارتفاع السعر', 'growth']],
            ],
            'en' => [
                'purpose' => [['For living', 'home'], ['Investment', 'growth']],

                'currency' => [['US dollars', 'wallet'], ['Iraqi dinars', 'wallet']],
                'property_type' => [['Apartment', 'apartment'], ['Villa', 'villa'], ['House', 'house'], ['Land', 'land']],
                'area' => [['No area preference', 'location']],
                'workplace' => [['Not important', 'briefcase']],
                'schools' => [['Yes', 'check'], ['No', 'close']],
                'family' => [['Yes', 'family'], ['No', 'close']],
                'timeframe' => [['Within 3 months', 'clock'], ['Within 6 months', 'clock'], ['Within a year', 'calendar']],
                'payment' => [['Cash', 'cash'], ['Instalments', 'card'], ['Either works', 'split']],
                'risk' => [['Low', 'shield'], ['Medium', 'balance'], ['High', 'bolt']],
                'return_goal' => [['Rental income', 'key'], ['Price growth', 'growth']],
            ],
            default => [
                'purpose' => [['بۆ نیشتەجێبوون', 'home'], ['بۆ وەبەرهێنان', 'growth']],

                'currency' => [['دۆلاری ئەمریکی', 'wallet'], ['دیناری عێراقی', 'wallet']],
                'property_type' => [['شوقە', 'apartment'], ['ڤێلا', 'villa'], ['ماڵ', 'house'], ['زەوی', 'land']],
                'area' => [['ناوچەی دیاریکراوم نییە', 'location']],
                'workplace' => [['گرنگ نییە', 'briefcase']],
                'schools' => [['بەڵێ', 'check'], ['نەخێر', 'close']],
                'family' => [['بەڵێ', 'family'], ['نەخێر', 'close']],
                'timeframe' => [['لە 3 مانگدا', 'clock'], ['لە 6 مانگدا', 'clock'], ['لە ساڵێکدا', 'calendar']],
                'payment' => [['نەقد', 'cash'], ['بە قیست', 'card'], ['هەردووکیان', 'split']],
                'risk' => [['نزم', 'shield'], ['مامناوەند', 'balance'], ['بەرز', 'bolt']],
                'return_goal' => [['داهاتی کرێ', 'key'], ['بەرزبوونەوەی نرخ', 'growth']],
            ],
        };

        $items = $sets[$slot] ?? [];

        if ($canFinish) {
            $items[] = [match ($locale) {
                'ar' => 'كافي، اعرض النتائج',
                'en' => 'Show results',
                default => 'بەسە، ئەنجامەکان پیشان بدە',
            }, 'sparkles'];
        }

        return array_map(
            static fn (array $item): array => [
                'label' => $item[0],
                'value' => $item[0],
                'icon' => $item[1],
            ],
            $items,
        );
    }

    /**
     * The budget quick replies, denominated (v5, BLOCKER 6).
     *
     * Three shapes, decided by what the interview already knows:
     *
     *   CLARIFYING a parked bare figure — combined amount-and-currency
     *   readings of THAT figure ("150 هەزار دۆلار" / "150 ملیۆن دینار"),
     *   plus the literal escape ("بەس 150") for the person whose number
     *   really was the number.
     *
     *   CURRENCY KNOWN — ranges in that currency only. The IQD ranges
     *   are in the millions, which is what a property budget in dinars
     *   looks like; the 150,000-dinar apartment the review called out
     *   can no longer be clicked into existence.
     *
     *   CURRENCY UNKNOWN (the person declined to name one) — one worked
     *   example per supported currency, each fully denominated, so the
     *   click that answers the amount answers the unit with it.
     *
     * @param  array<string, mixed>  $criteria
     * @return list<array{label: string, value: string, icon: string}>
     */
    private function budgetReplies(string $locale, array $criteria, bool $canFinish): array
    {
        $pending = $criteria[AdvisorConversationService::BUDGET_CLARIFY] ?? null;
        $pending = is_scalar($pending) && (string) $pending !== '' ? (string) $pending : null;

        $currency = AdvisorCurrency::storable(
            is_string($criteria['currency'] ?? null) ? $criteria['currency'] : null
        );

        [$kUsd, $mIqd, $literal] = match ($locale) {
            'ar' => [' ألف دولار', ' مليون دينار', 'فقط '],
            'en' => [' thousand USD', ' million IQD', 'Exactly '],
            default => [' هەزار دۆلار', ' ملیۆن دینار', 'بەس '],
        };

        if ($pending !== null) {
            $labels = [$pending.$kUsd, $pending.$mIqd, $literal.$pending];
        } elseif ($currency === AdvisorCurrency::USD) {
            $labels = ['50'.$kUsd, '100'.$kUsd, '150'.$kUsd, '250'.$kUsd];
        } elseif ($currency === AdvisorCurrency::IQD) {
            $labels = ['100'.$mIqd, '200'.$mIqd, '300'.$mIqd, '500'.$mIqd];
        } else {
            $labels = ['150'.$kUsd, '200'.$mIqd];
        }

        $items = array_map(static fn (string $label): array => [$label, 'wallet'], $labels);

        if ($canFinish) {
            $items[] = [match ($locale) {
                'ar' => 'كافي، اعرض النتائج',
                'en' => 'Show results',
                default => 'بەسە، ئەنجامەکان پیشان بدە',
            }, 'sparkles'];
        }

        return array_map(
            static fn (array $item): array => [
                'label' => $item[0],
                'value' => $item[0],
                'icon' => $item[1],
            ],
            $items,
        );
    }

    /** @return list<array{label: string, value: string, icon: string}> */
    private function advisoryReplies(string $locale): array
    {
        $labels = match ($locale) {
            'ar' => [
                ['قارن الأول والثاني', 'balance'],
                ['أريد أرخص', 'wallet'],
                ['ليش الأول؟', 'sparkles'],
            ],
            'en' => [
                ['Compare the first two', 'balance'],
                ['Show cheaper options', 'wallet'],
                ['Why the first one?', 'sparkles'],
            ],
            default => [
                ['بەراوردی یەکەم و دووەم بکە', 'balance'],
                ['هەرزانتر دەوێت', 'wallet'],
                ['بۆچی یەکەمیان؟', 'sparkles'],
            ],
        };

        return array_map(
            static fn (array $item): array => [
                'label' => $item[0],
                'value' => $item[0],
                'icon' => $item[1],
            ],
            $labels,
        );
    }

    public function skipValue(string $locale): string
    {
        return match ($this->normalize($locale)) {
            'ar' => 'مو مهم',
            'en' => 'Not important',
            default => 'گرنگ نییە',
        };
    }

    public function languageName(string $locale): string
    {
        return match ($this->normalize($locale)) {
            'ar' => 'conversational Iraqi Arabic',
            'en' => 'natural English',
            default => 'natural Kurdish Sorani (ckb, Arabic script)',
        };
    }

    /**
     * Translate canonical database values before they reach the summary panel.
     *
     * @param  list<array<string, mixed>>  $summary
     * @return list<array<string, mixed>>
     */
    public function localizeSummary(array $summary, string $locale): array
    {
        $locale = $this->normalize($locale);

        /*
         * The person's ACTUAL currency, read from the summary's own currency
         * row before any localization, so the budget row can speak it
         * (Phase 18). budgetLabel() used to say dollars unconditionally —
         * directly beside a currency row that said dinars.
         */
        $currency = null;
        foreach ($summary as $row) {
            if (($row['key'] ?? null) === 'currency' && ($row['answered'] ?? false)) {
                $currency = is_string($row['value'] ?? null) ? $row['value'] : null;
            }
        }

        return array_map(function (array $row) use ($locale, $currency): array {
            if (($row['answered'] ?? false) && array_key_exists('value', $row)) {
                /*
                 * Additive (Phase 18): the canonical value survives beside
                 * the localized one, so the edit box can prefill what the
                 * criteria actually hold instead of a formatted label.
                 * Nothing that reads `value` changes.
                 */
                $row['raw_value'] = trim((string) $row['value']);
                $row['value'] = $this->displayValue(
                    (string) ($row['key'] ?? ''),
                    $row['value'],
                    $locale,
                    $currency,
                );
            }

            return $row;
        }, $summary);
    }

    public function displayValue(string $slot, mixed $value, string $locale, ?string $currency = null): string
    {
        $locale = $this->normalize($locale);
        $raw = trim((string) $value);
        $normalised = mb_strtolower($raw);

        if ($raw === '') {
            return '';
        }

        if ($slot === 'budget') {
            return $this->budgetLabel($raw, $locale, $currency);
        }

        /*
         * Correction v4, BLOCKER 3. The summary must show what the person
         * ACTUALLY said about currency, including that they said nothing
         * classifiable. A blank cell would read as "not asked"; the
         * explicit "not stated" reads as what it is, and it is what the
         * admin and the lead page show too.
         */
        if ($slot === 'currency') {
            $upper = strtoupper($raw);

            if ($upper === AdvisorCurrency::USD) {
                return match ($locale) {
                    'ar' => 'دولار أمريكي (USD)',
                    'en' => 'US dollars (USD)',
                    default => 'دۆلاری ئەمریکی (USD)',
                };
            }

            if ($upper === AdvisorCurrency::IQD) {
                return match ($locale) {
                    'ar' => 'دينار عراقي (IQD)',
                    'en' => 'Iraqi dinars (IQD)',
                    default => 'دیناری عێراقی (IQD)',
                };
            }

            return (string) Lang::get('advisor.chat.slots.currency_unknown', [], $locale);
        }

        if ($slot === 'purpose') {
            $investment = $this->containsAny($normalised, ['investment', 'invest', 'استثمار', 'وەبەرهێنان']);

            return match ($locale) {
                'ar' => $investment ? 'استثمار' : 'سكن',
                'en' => $investment ? 'Investment' : 'For living',
                default => $investment ? 'وەبەرهێنان' : 'نیشتەجێبوون',
            };
        }

        if ($slot === 'property_type') {
            return $this->propertyTypeLabel($raw, $locale);
        }

        if ($this->isSkipped($raw)) {
            return match ($locale) {
                'ar' => $slot === 'area' ? 'ما عندي منطقة محددة' : 'مو مهم',
                'en' => $slot === 'area' ? 'No area preference' : 'Not important',
                default => $slot === 'area' ? 'ناوچەی دیاریکراوم نییە' : 'گرنگ نییە',
            };
        }

        if (in_array($slot, ['schools', 'family'], true)) {
            $yes = $this->containsAny($normalised, ['yes', 'نعم', 'اي', 'إي', 'بەڵێ', 'بلي']);
            $no = $this->containsAny($normalised, ['no', 'لا', 'كلا', 'نەخێر', 'نخير']);

            if ($yes || $no) {
                return match ($locale) {
                    'ar' => $yes ? 'نعم' : 'لا',
                    'en' => $yes ? 'Yes' : 'No',
                    default => $yes ? 'بەڵێ' : 'نەخێر',
                };
            }
        }

        if ($slot === 'payment') {
            $both = $this->containsAny($normalised, ['either', 'both', 'الاثنين', 'الاثنان', 'هەردوو']);
            $instalments = $this->containsAny($normalised, ['instal', 'قسط', 'أقساط', 'اقساط', 'قیست']);
            $cash = $this->containsAny($normalised, ['cash', 'نقد', 'نەقد']);

            if ($both || $instalments || $cash) {
                return match ($locale) {
                    'ar' => $both ? 'نقد أو أقساط' : ($instalments ? 'أقساط' : 'نقد'),
                    'en' => $both ? 'Cash or instalments' : ($instalments ? 'Instalments' : 'Cash'),
                    default => $both ? 'نەقد یان قیست' : ($instalments ? 'بە قیست' : 'نەقد'),
                };
            }
        }

        if ($slot === 'risk') {
            $level = match (true) {
                $this->containsAny($normalised, ['low', 'منخفض', 'نزم']) => 'low',
                $this->containsAny($normalised, ['high', 'عالي', 'عالية', 'بەرز']) => 'high',
                default => 'medium',
            };

            return match ($locale) {
                'ar' => ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية'][$level],
                'en' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'][$level],
                default => ['low' => 'نزم', 'medium' => 'مامناوەند', 'high' => 'بەرز'][$level],
            };
        }

        if ($slot === 'return_goal') {
            $rent = $this->containsAny($normalised, ['rent', 'rental', 'إيجار', 'ايجار', 'كرێ']);

            return match ($locale) {
                'ar' => $rent ? 'دخل إيجار' : 'ارتفاع السعر',
                'en' => $rent ? 'Rental income' : 'Price growth',
                default => $rent ? 'داهاتی کرێ' : 'بەرزبوونەوەی نرخ',
            };
        }

        return $raw;
    }

    /** @param array<string, mixed> $criteria */
    private function summaryText(array $criteria, string $locale): string
    {
        $parts = [];
        $purpose = $criteria['purpose'] ?? null;
        $budget = $criteria['budget'] ?? null;
        $type = $criteria['property_type'] ?? null;
        $area = $criteria['area'] ?? null;

        if (is_string($type) && ! $this->isSkipped($type)) {
            $parts[] = $this->propertyTypeLabel($type, $locale);
        }

        if (is_string($purpose) && ! $this->isSkipped($purpose)) {
            $parts[] = $this->purposeLabel($purpose, $locale);
        }

        if ((is_string($budget) || is_numeric($budget)) && (string) $budget !== '') {
            // The criteria's own currency travels with its budget (Phase 18).
            $currency = is_string($criteria['currency'] ?? null) ? $criteria['currency'] : null;
            $parts[] = $this->budgetLabel((string) $budget, $locale, $currency);
        }

        if (is_string($area) && ! $this->isSkipped($area)) {
            $parts[] = match ($locale) {
                'ar' => 'في '.$area,
                'en' => 'in '.$area,
                default => 'لە '.$area,
            };
        }

        return implode($locale === 'en' ? ', ' : '، ', array_values(array_filter($parts)));
    }

    private function purposeLabel(string $value, string $locale): string
    {
        $normalised = mb_strtolower($value);
        $investment = $this->containsAny($normalised, ['invest', 'استثمار', 'وەبەرهێنان']);

        return match ($locale) {
            'ar' => $investment ? 'للاستثمار' : 'للسكن',
            'en' => $investment ? 'for investment' : 'for living',
            default => $investment ? 'بۆ وەبەرهێنان' : 'بۆ نیشتەجێبوون',
        };
    }

    private function propertyTypeLabel(string $value, string $locale): string
    {
        $normalised = mb_strtolower($value);
        $key = match (true) {
            $this->containsAny($normalised, ['apartment', 'flat', 'شقة', 'شقه', 'شوقە']) => 'apartment',
            $this->containsAny($normalised, ['villa', 'فيلا', 'ڤێلا', 'ڤیلا']) => 'villa',
            $this->containsAny($normalised, ['house', 'بيت', 'منزل', 'ماڵ']) => 'house',
            $this->containsAny($normalised, ['land', 'plot', 'أرض', 'ارض', 'زەوی']) => 'land',
            $this->containsAny($normalised, ['office', 'مكتب', 'ئۆفیس', 'نووسینگە']) => 'office',
            $this->containsAny($normalised, ['shop', 'store', 'محل', 'دوکان']) => 'shop',
            default => null,
        };

        if ($key === null) {
            return $value;
        }

        return match ($locale) {
            'ar' => [
                'apartment' => 'شقة', 'villa' => 'فيلا', 'house' => 'بيت',
                'land' => 'أرض', 'office' => 'مكتب', 'shop' => 'محل',
            ][$key],
            'en' => [
                'apartment' => 'Apartment', 'villa' => 'Villa', 'house' => 'House',
                'land' => 'Land', 'office' => 'Office', 'shop' => 'Shop',
            ][$key],
            default => [
                'apartment' => 'شوقە', 'villa' => 'ڤێلا', 'house' => 'ماڵ',
                'land' => 'زەوی', 'office' => 'نووسینگە', 'shop' => 'دوکان',
            ][$key],
        };
    }

    private function budgetLabel(string $value, string $locale, ?string $currency = null): string
    {
        $normalised = strtr(mb_strtolower($value), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $normalised = str_replace(['٬', ','], '', $normalised);

        if (preg_match('/(\d+(?:\.\d+)?)\s*(k|m|thousand|million|ألف|الف|هەزار|هزار|مليون|ملیۆن)?/u', $normalised, $matches) !== 1) {
            return $value;
        }

        $number = (float) $matches[1];
        $unit = $matches[2] ?? '';

        if (in_array($unit, ['k', 'thousand', 'ألف', 'الف', 'هەزار', 'هزار'], true)) {
            $number *= 1000;
        } elseif (in_array($unit, ['m', 'million', 'مليون', 'ملیۆن'], true)) {
            $number *= 1000000;
        }

        $display = number_format($number, abs($number - round($number)) < 0.000001 ? 0 : 2);

        /*
         * The label speaks the person's OWN currency (Phase 18). A dinar
         * budget used to be captioned in dollars in the two most prominent
         * recap surfaces — the editable summary and the completion sentence —
         * directly contradicting the currency row beside it. IQD gets dinar
         * wording; USD keeps today's wording; an unknown/unset currency keeps
         * the historical dollar fallback so the rare intermediate turn where
         * a budget lands before its currency renders exactly as before.
         */
        if (AdvisorCurrency::storable($currency) === AdvisorCurrency::IQD) {
            return match ($locale) {
                'ar' => $display.' دينار عراقي',
                'en' => $display.' IQD',
                default => $display.' دیناری عێراقی',
            };
        }

        return match ($locale) {
            'ar' => $display.' دولار',
            'en' => '$'.$display,
            default => $display.' دۆلار',
        };
    }

    private function isSkipped(string $value): bool
    {
        $normalised = mb_strtolower(trim($value));

        return in_array($normalised, [
            'مو مهم', 'ما عندي منطقة محددة', 'not important', 'no area preference',
            'گرنگ نییە', 'ناوچەی دیاریکراوم نییە',
        ], true);
    }

    /** @param list<string> $needles */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function safeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 40);
    }
}
