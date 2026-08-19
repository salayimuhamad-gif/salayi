<?php

declare(strict_types=1);

namespace Tests\Unit\Advisor;

use App\Modules\Advisor\Support\NumericGuard;
use Tests\TestCase;

/**
 * The multibyte windowing matrix (Phase 14).
 *
 * PREG_OFFSET_CAPTURE reports byte offsets and the guard's context windows
 * are carved in characters. Before this phase the two were mixed, so in
 * Arabic-script prose whether a unit marker was seen depended on how long
 * the text BEFORE the number happened to be — `37%`, `45 دۆلار` and
 * `90 مەتر` classified as exempt counts in most phrasings and shipped
 * without any grounding check, while the identical English sentences were
 * checked. Every previously-leaking example from the Phase 14 planning
 * evidence is a row here, alongside the English rows that must not move
 * and the exemptions that must stay exempt.
 */
final class NumericGuardTest extends TestCase
{
    private function guard(): NumericGuard
    {
        return new NumericGuard;
    }

    /**
     * The single claim of a text, as "raw=kind" plus whether it is checked.
     *
     * @return array{raw: string, kind: string, checked: bool}
     */
    private function only(string $text): array
    {
        $claims = $this->guard()->extract($text);

        $this->assertCount(1, $claims, 'expected exactly one numeric claim in: '.$text);

        return [
            'raw' => $claims[0]->raw,
            'kind' => $claims[0]->kind,
            'checked' => $claims[0]->requiresGrounding(),
        ];
    }

    /* --------------------------- the Arabic-script rows that used to leak */

    public function test_kurdish_and_arabic_percentages_are_percentage_claims(): void
    {
        foreach ([
            'نرخەکان لە ئەنکاوە ڕێک 37% بەرزبوونەوە.',
            'بەپێی زانیارییە تۆمارکراوەکانی تیمی چاودێری بازاڕ لە ئەنکاوە، نرخەکان بە ڕێژەی 37% بەرزبوونەتەوە.',
            'ارتفعت الأسعار في عنكاوة بنسبة 37% تقريبا.',
        ] as $text) {
            $claim = $this->only($text);

            $this->assertSame('percentage', $claim['kind'], $text);
            $this->assertTrue($claim['checked'], $text);
        }
    }

    public function test_small_kurdish_money_is_a_money_claim_at_every_prefix_length(): void
    {
        // The pre-fix behavior depended on the prefix geometry: short and
        // long leaked as counts, mid worked by accident. All three must be
        // money now, plus the Arabic equivalent.
        foreach ([
            'کرێ 45 دۆلارە.',
            'کرێی خزمەتگوزاری 45 دۆلارە بۆ هەر مەترێک.',
            'بەپێی زانیارییە تۆمارکراوەکانی تیمی چاودێری بازاڕ لە ئەنکاوە، کرێی خزمەتگوزاری بەڕێوەبردنی مانگانە بۆ هەر شوقەیەک 45 دۆلارە.',
            'رسوم الخدمة 45 دولار لكل متر.',
        ] as $text) {
            $claim = $this->only($text);

            $this->assertSame('money', $claim['kind'], $text);
            $this->assertTrue($claim['checked'], $text);
        }
    }

    public function test_kurdish_distances_are_distance_claims_at_short_and_long_prefixes(): void
    {
        foreach ([
            'قوتابخانەکە تەنها 90 مەتر دوورە لە پڕۆژەکە.',
            'بەپێی زانیارییە تۆمارکراوەکانی تیمی چاودێری بازاڕ لە ئەنکاوە، قوتابخانە نزیکەکە تەنها 90 مەتر دوورە.',
        ] as $text) {
            $claim = $this->only($text);

            $this->assertSame('distance', $claim['kind'], $text);
            $this->assertTrue($claim['checked'], $text);
        }
    }

    /* -------------------------------------- English must not move an inch */

    public function test_english_classifications_are_unchanged(): void
    {
        $rows = [
            ['Prices in Ankawa rose by exactly 37% this year.', '37', 'percentage', true],
            ['The service fee is 45 dollars per meter.', '45', 'money', true],
            ['The school is only 90 meters away from the project.', '90', 'distance', true],
            ['The apartment costs 185000 dollars in Ankawa.', '185000', 'money', true],
        ];

        foreach ($rows as [$text, $raw, $kind, $checked]) {
            $claim = $this->only($text);

            $this->assertSame($raw, $claim['raw'], $text);
            $this->assertSame($kind, $claim['kind'], $text);
            $this->assertSame($checked, $claim['checked'], $text);
        }
    }

    /* ------------------------------------ deliberate exemptions stay put */

    public function test_years_and_bare_counts_remain_exempt_in_both_scripts(): void
    {
        foreach ([
            'لە ساڵی 2026 پڕۆژەکە تەواو دەبێت.' => 'year',
            'شوقەکە 3 ژووری نوستنی هەیە.' => 'count',
            'The project finishes in 2026.' => 'year',
            'The apartment has 3 bedrooms.' => 'count',
        ] as $text => $kind) {
            $claim = $this->only($text);

            $this->assertSame($kind, $claim['kind'], $text);
            $this->assertFalse($claim['checked'], $text);
        }
    }

    /* ------------------------------------------------- grounding verdicts */

    public function test_checked_arabic_script_claims_fail_on_empty_evidence_and_pass_on_matching(): void
    {
        $guard = $this->guard();

        foreach ([
            'نرخەکان لە ئەنکاوە ڕێک 37% بەرزبوونەوە.' => '37',
            'کرێ 45 دۆلارە.' => '45',
            'ارتفعت الأسعار في عنكاوة بنسبة 37% تقريبا.' => '37',
        ] as $text => $evidence) {
            $this->assertFalse(
                $guard->validate($text, [])['grounded'],
                'with no evidence, the claim must be refused: '.$text,
            );
            $this->assertTrue(
                $guard->validate($text, [$evidence])['grounded'],
                'with matching evidence, the claim must pass: '.$text,
            );
        }
    }

    public function test_the_rounding_tolerance_contract_is_untouched(): void
    {
        $guard = $this->guard();

        // The pre-existing contract from AdvisorGroundingTest, restated here
        // so the windowing change is proven not to touch the verdict logic:
        // rounding to the claim's own precision is honest, invention is not.
        $this->assertTrue($guard->validate('نزیکەی 244,000 دۆلار', ['243500'])['grounded']);
        $this->assertFalse($guard->validate('نزیکەی 245,000 دۆلار', ['243500'])['grounded']);
    }

    public function test_large_kurdish_prices_are_still_money_claims(): void
    {
        $claim = $this->only('نرخی شوقەکە 185000 دۆلارە لە ئەنکاوە.');

        $this->assertSame('money', $claim['kind']);
        $this->assertTrue($claim['checked']);
        $this->assertFalse($this->guard()->validate('نرخی شوقەکە 185000 دۆلارە.', [])['grounded']);
    }
}
