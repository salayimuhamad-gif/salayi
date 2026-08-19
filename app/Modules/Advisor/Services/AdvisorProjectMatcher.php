<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Support\AdvisorCurrency;
use App\Modules\Geography\Enums\PlaceCategoryKey;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectNearbyPlace;
use Illuminate\Support\Collection;

/**
 * Builds grounded project recommendations from published database records.
 * AI never chooses projects, prices, or which condition is relaxed.
 */
final class AdvisorProjectMatcher
{
    private const MAX_PROJECTS = 200;

    private const RESULT_LIMIT = 4;

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function recommend(array $criteria, string $locale): array
    {
        $locale = in_array($locale, ['ckb', 'ar', 'en'], true) ? $locale : 'ckb';

        /** @var Collection<int, Project> $projects */
        $projects = Project::query()
            ->published()
            ->with([
                'area:id,name_ckb,name_ar,name_en,search_key',
                'developer:id,name_ckb,name_ar,name_en',
            ])
            ->orderByDesc('published_at')
            ->limit(self::MAX_PROJECTS)
            ->get();

        if ($projects->isEmpty()) {
            return $this->emptyResult($locale);
        }

        $ids = $projects->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $prices = $this->prices($ids);
        $schools = $this->nearestSchools($ids);
        $budget = $this->budget($criteria['budget'] ?? null);
        /*
         * Correction v4, BLOCKER 3: the budget's UNIT travels with the
         * budget. Without it, `budgetState()` compared a bare number to a
         * USD price — which silently read a 200,000,000 IQD budget as
         * 200 million dollars and marked every project on the platform
         * "within budget".
         */
        $budgetCurrency = AdvisorCurrency::storable(
            is_string($criteria['currency'] ?? null) ? $criteria['currency'] : null
        );
        $areaPreference = $this->areaPreference($criteria['area'] ?? null);
        $desiredType = $this->desiredType($criteria['property_type'] ?? null);
        $schoolRequired = $this->yes($criteria['schools'] ?? null);
        $instalmentsPreferred = $this->instalments($criteria['payment'] ?? null);
        $purpose = ($criteria['purpose'] ?? null) === 'investment' ? 'investment' : 'residence';
        $evaluated = [];

        foreach ($projects as $project) {
            $price = $prices->get($project->id);
            $school = $schools->get($project->id);
            $typeMatch = $this->typeMatches($desiredType, $project->project_type->value);
            $areaMatch = $this->areaMatches($areaPreference, $project);
            $budgetState = $this->budgetState($budget, $budgetCurrency, $price);
            $schoolMatch = ! $schoolRequired || $school !== null;
            $differences = [];
            $positive = [];

            if ($budgetState === 'within') {
                $positive[] = $this->text($locale, 'within_budget');
            } elseif ($budgetState === 'above') {
                $differences[] = $this->overBudgetText($locale, $budget, $price);
            } elseif ($budget !== null) {
                $differences[] = $this->text($locale, 'price_unavailable');
            }

            if ($typeMatch) {
                $positive[] = $this->text($locale, 'type_match');
            } elseif ($desiredType !== null) {
                $differences[] = $this->text($locale, 'type_differs');
            }

            if ($areaMatch) {
                if ($areaPreference !== null) {
                    $positive[] = $this->text($locale, 'area_match');
                }
            } elseif ($areaPreference !== null) {
                $differences[] = $this->text($locale, 'outside_area');
            }

            if ($schoolRequired) {
                if ($school !== null) {
                    $positive[] = $this->schoolText($locale, (int) $school->straight_line_m);
                } else {
                    $differences[] = $this->text($locale, 'school_unconfirmed');
                }
            }

            $softNote = $instalmentsPreferred && empty((array) $project->payment_plans)
                ? $this->text($locale, 'instalments_unconfirmed')
                : null;

            $exact = $differences === [];
            $score = $this->score(
                project: $project,
                purpose: $purpose,
                budget: $budget,
                price: $price,
                budgetState: $budgetState,
                typeMatch: $typeMatch,
                areaMatch: $areaMatch,
                schoolRequired: $schoolRequired,
                schoolMatch: $schoolMatch,
                differenceCount: count($differences),
            );

            $evaluated[] = [
                'exact' => $exact,
                'score' => $score,
                'difference_count' => count($differences),
                'project' => $project,
                'price' => $price,
                'school' => $school,
                'positive' => array_slice($positive, 0, 3),
                'budget_state' => $budgetState,
                'differences' => array_slice($differences, 0, 3),
                'soft_note' => $softNote,
            ];
        }

        $exact = array_values(array_filter($evaluated, static fn (array $row): bool => $row['exact']));
        $pool = $exact !== [] ? $exact : $evaluated;

        usort($pool, static function (array $a, array $b): int {
            if ($a['difference_count'] !== $b['difference_count']) {
                return $a['difference_count'] <=> $b['difference_count'];
            }

            return $b['score'] <=> $a['score'];
        });

        $selected = array_slice($pool, 0, self::RESULT_LIMIT);
        $status = $exact !== [] ? 'exact' : 'near';
        $items = array_map(fn (array $row): array => $this->card($row, $locale, $status), $selected);

        return [
            'status' => $status,
            'message' => $this->resultMessage($locale, $status, count($items)),
            'title' => $this->resultTitle($locale, $status),
            'subtitle' => $this->resultSubtitle($locale, $status),
            'button_label' => $this->text($locale, 'open_project'),
            'items' => $items,
            'locale' => $locale,
        ];
    }

    /**
     * @param  list<int>  $projectIds
     * @return Collection<int, PriceRecord>
     */
    private function prices(array $projectIds): Collection
    {
        return PriceRecord::query()
            ->published()
            ->where('scope_type', 'project')
            ->whereIn('scope_id', $projectIds)
            ->whereNotNull('price')
            ->orderBy('price')
            ->get()
            ->groupBy('scope_id')
            ->map(static fn (Collection $records): PriceRecord => $records->first());
    }

    /**
     * @param  list<int>  $projectIds
     * @return Collection<int, ProjectNearbyPlace>
     */
    private function nearestSchools(array $projectIds): Collection
    {
        return ProjectNearbyPlace::query()
            ->whereIn('project_id', $projectIds)
            ->where('is_hidden', false)
            ->where('is_stale', false)
            ->whereHas('place.category', static fn ($query) => $query->whereIn('key', [
                PlaceCategoryKey::School->value,
                PlaceCategoryKey::Kindergarten->value,
                PlaceCategoryKey::University->value,
                PlaceCategoryKey::Institute->value,
            ]))
            ->with(['place.category'])
            ->orderBy('straight_line_m')
            ->get()
            ->groupBy('project_id')
            ->map(static fn (Collection $rows): ProjectNearbyPlace => $rows->first());
    }

    private function score(
        Project $project,
        string $purpose,
        ?float $budget,
        ?PriceRecord $price,
        string $budgetState,
        bool $typeMatch,
        bool $areaMatch,
        bool $schoolRequired,
        bool $schoolMatch,
        int $differenceCount,
    ): int {
        $score = 100 - ($differenceCount * 22);
        $score += $typeMatch ? 10 : 0;
        $score += $areaMatch ? 8 : 0;
        $score += ($schoolRequired && $schoolMatch) ? 6 : 0;

        /*
         * v4 BLOCKER 3: `$budgetState === 'within'` already proves the two
         * currencies matched — `budgetState()` refuses to return 'within'
         * otherwise — so the redundant USD test is gone rather than
         * duplicated. Keeping it would have re-introduced the dollar
         * assumption in the one place that survives a currency fix.
         */
        if ($budgetState === 'within' && $budget !== null && $price !== null) {
            $amount = (float) $price->price;
            $score += (int) round(max(0, 12 - (($budget - $amount) / max($budget, 1)) * 5));
        }

        $quality = $purpose === 'investment'
            ? (int) ($project->investment_score ?? 0)
            : (int) ($project->family_suitability_score ?? 0);
        $score += (int) round(max(0, min(100, $quality)) / 10);

        return max(0, min(130, $score));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function card(array $row, string $locale, string $status): array
    {
        /** @var Project $project */
        $project = $row['project'];
        /** @var PriceRecord|null $price */
        $price = $row['price'];
        /** @var ProjectNearbyPlace|null $school */
        $school = $row['school'];
        $notes = $row['differences'];

        if ($row['soft_note'] !== null) {
            $notes[] = $row['soft_note'];
        }

        return [
            'id' => $project->id,
            'slug' => $project->slug,
            'name' => $project->name($locale),
            'area' => $project->area?->name($locale),
            'developer' => $project->developer?->name($locale),
            'type' => $this->projectTypeLabel($project->project_type->value, $locale),
            'price_label' => $this->priceLabel($price, $locale),
            'fit_label' => $status === 'exact'
                ? $this->text($locale, 'matches_request')
                : $this->text($locale, 'closest_alternative'),
            /*
             * v5, BLOCKER 10: HOW the budget figured in this card —
             * 'within' / 'above' / 'unknown' (currencies not comparable)
             * / 'not_set'. The admin surface uses it to say out loud
             * when a budget existed but was not compared, instead of
             * letting a silently budget-blind ranking read as a
             * budget-aware one.
             */
            'budget_state' => $row['budget_state'] ?? 'not_set',
            'reasons' => $row['positive'],
            'differences' => array_values(array_unique($notes)),
            'school_distance_m' => $school?->straight_line_m,
            /*
             * Correction §6.6, the card that got away: the open button must
             * land on the SELECTED language's project page. localized_route
             * resolves the /ar and /en route names when they exist and falls
             * back to the canonical Kurdish path — the same rule as every
             * other surface this correction touched. The interview's $locale
             * is passed explicitly because the matcher can run in queue-ish
             * contexts where the app locale is not the conversation's.
             */
            'url' => localized_route('projects.show', ['slug' => $project->slug], $locale),
        ];
    }

    /**
     * Whether a project's price sits inside the stated budget.
     *
     * Correction v4, BLOCKER 3. Three things must all be true before two
     * numbers may be compared: a budget exists, a price exists, and they
     * are denominated in the SAME currency. Anything else is `unknown`,
     * which the caller renders as "price unavailable for comparison" —
     * honest, and visibly different from "within budget".
     *
     * The old test was `price->currency !== 'USD'`, which encoded the
     * assumption that every budget was in dollars. It is now a symmetric
     * comparison through {@see AdvisorCurrency::comparable()}, so an IQD
     * budget against a USD price and a USD budget against an IQD price are
     * both `unknown` — and an unknown budget currency can never be
     * compared to anything at all.
     *
     * Nothing here converts. A conversion would need a dated rate from a
     * recorded source and a visible basis, and inventing one inside a
     * scoring loop is how a match score becomes a lie.
     */
    private function budgetState(?float $budget, ?string $budgetCurrency, ?PriceRecord $price): string
    {
        if ($budget === null) {
            return 'not_set';
        }

        if ($price === null || ! AdvisorCurrency::comparable($budgetCurrency, (string) $price->currency)) {
            return 'unknown';
        }

        return (float) $price->price <= $budget ? 'within' : 'above';
    }

    private function budget(mixed $value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }

        $number = (float) preg_replace('/[^0-9.]/', '', (string) $value);

        return $number > 0 ? $number : null;
    }

    private function desiredType(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = mb_strtolower(trim((string) $value));

        return in_array($value, ['apartment', 'villa', 'house', 'land', 'office', 'shop'], true)
            ? $value
            : null;
    }

    private function typeMatches(?string $desired, string $projectType): bool
    {
        if ($desired === null) {
            return true;
        }

        $allowed = [
            'apartment' => ['residential', 'tower', 'compound', 'mixed_use', 'township'],
            'villa' => ['villa', 'compound', 'township', 'residential'],
            'house' => ['residential', 'compound', 'township', 'villa'],
            'land' => [],
            'office' => ['office', 'commercial', 'mixed_use', 'tower'],
            'shop' => ['retail', 'commercial', 'mixed_use'],
        ];

        return in_array($projectType, $allowed[$desired] ?? [], true);
    }

    private function areaPreference(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        $normalised = sorani_search_key($text);
        $ignored = [
            '', 'مو مهم', 'ما عندي منطقة محددة', 'ما عندي منطقه محدده',
            'not important', 'no area preference', 'گرنگ نییە',
            'ناوچەی دیاریکراوم نییە', 'erbil', 'hawler', 'اربيل', 'أربيل', 'هەولێر',
        ];

        foreach ($ignored as $item) {
            if ($normalised === sorani_search_key($item)) {
                return null;
            }
        }

        return $text;
    }

    private function areaMatches(?string $preference, Project $project): bool
    {
        if ($preference === null) {
            return true;
        }

        if ($project->area === null) {
            return false;
        }

        $needle = sorani_search_key($preference);
        $haystack = sorani_search_key(implode(' ', array_filter([
            $project->area->name_ckb,
            $project->area->name_ar,
            $project->area->name_en,
            $project->area->search_key,
        ])));

        return $needle !== '' && (str_contains($haystack, $needle) || str_contains($needle, $haystack));
    }

    private function yes(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $text = mb_strtolower(trim((string) $value));
        $no = ['لا', 'كلا', 'نەخێر', 'نە', 'no', 'not important', 'مو مهم', 'گرنگ نییە'];

        foreach ($no as $needle) {
            if ($text === $needle || str_contains($text, $needle)) {
                return false;
            }
        }

        foreach (['نعم', 'اي', 'إي', 'بەڵێ', 'بلي', 'yes', 'important', 'گرنگە'] as $needle) {
            if ($text === $needle || str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function instalments(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $text = mb_strtolower((string) $value);

        return str_contains($text, 'قسط')
            || str_contains($text, 'أقساط')
            || str_contains($text, 'اقساط')
            || str_contains($text, 'قیست')
            || str_contains($text, 'instal')
            || str_contains($text, 'install');
    }

    private function priceLabel(?PriceRecord $price, string $locale): string
    {
        if ($price === null) {
            return $this->text($locale, 'price_unavailable');
        }

        $amount = number_format((float) $price->price, 0, '.', ',');
        $currency = strtoupper((string) $price->currency);

        return match ($locale) {
            'ar' => 'أقل سعر منشور: '.$amount.' '.$currency,
            'en' => 'Lowest published price: '.$amount.' '.$currency,
            default => 'کەمترین نرخی بڵاوکراوە: '.$amount.' '.$currency,
        };
    }

    private function overBudgetText(string $locale, ?float $budget, ?PriceRecord $price): string
    {
        if ($budget === null || $price === null) {
            return $this->text($locale, 'above_budget');
        }

        $difference = max(0, (float) $price->price - $budget);
        $formatted = number_format($difference, 0, '.', ',');

        /*
         * The price's OWN currency, exactly as priceLabel() above states it
         * (Phase 18). budgetState() only reaches 'above' after
         * AdvisorCurrency::comparable() proved budget and price share one
         * currency, so this label is that currency — a hardcoded "USD" here
         * told every dinar buyer their shortfall in dollars, and the wrong
         * word travelled into the card facts the AI model is shown.
         */
        $currency = strtoupper((string) $price->currency);

        return match ($locale) {
            'ar' => 'أعلى من الميزانية بـ '.$formatted.' '.$currency,
            'en' => $formatted.' '.$currency.' above budget',
            default => $formatted.' '.$currency.' لە بودجەکەت زیاترە',
        };
    }

    private function schoolText(string $locale, int $metres): string
    {
        $distance = $metres >= 1000
            ? number_format($metres / 1000, 1).' km'
            : number_format($metres).' m';

        return match ($locale) {
            'ar' => 'مدرسة أو جامعة مؤكدة ضمن '.$distance,
            'en' => 'A verified school or university within '.$distance,
            default => 'قوتابخانە یان زانکۆیەکی پشتڕاستکراو لە دووری '.$distance,
        };
    }

    private function projectTypeLabel(string $type, string $locale): string
    {
        $labels = [
            'residential' => ['ckb' => 'نیشتەجێبوون', 'ar' => 'سكني', 'en' => 'Residential'],
            'commercial' => ['ckb' => 'بازرگانی', 'ar' => 'تجاري', 'en' => 'Commercial'],
            'mixed_use' => ['ckb' => 'تێکەڵ', 'ar' => 'متعدد الاستخدام', 'en' => 'Mixed use'],
            'compound' => ['ckb' => 'کۆمەڵگەی نیشتەجێبوون', 'ar' => 'مجمع سكني', 'en' => 'Compound'],
            'tower' => ['ckb' => 'بەرج', 'ar' => 'برج', 'en' => 'Tower'],
            'villa' => ['ckb' => 'ڤێلا', 'ar' => 'فيلا', 'en' => 'Villa'],
            'township' => ['ckb' => 'شارۆچکە', 'ar' => 'مدينة سكنية', 'en' => 'Township'],
            'office' => ['ckb' => 'ئۆفیس', 'ar' => 'مكاتب', 'en' => 'Office'],
            'retail' => ['ckb' => 'فرۆشگا', 'ar' => 'محلات', 'en' => 'Retail'],
            'hospitality' => ['ckb' => 'میوانداری', 'ar' => 'ضيافة', 'en' => 'Hospitality'],
            'industrial' => ['ckb' => 'پیشەسازی', 'ar' => 'صناعي', 'en' => 'Industrial'],
        ];

        return $labels[$type][$locale] ?? $type;
    }

    private function resultMessage(string $locale, string $status, int $count): string
    {
        return match ([$locale, $status]) {
            ['ar', 'exact'] => 'لقيت '.$count.' خيارات تناسب طلبك. شوف التفاصيل وافتح المشروع اللي يعجبك.',
            ['ar', 'near'] => 'ما لقيت خيار يطابق كل الشروط. هاي أقرب '.$count.' خيارات، والفرق موضّح بكل بطاقة.',
            ['en', 'exact'] => 'I found '.$count.' options that match your request. Open any project to see the details.',
            ['en', 'near'] => 'Nothing matches every condition. These are the '.$count.' closest alternatives, with each difference shown.',
            ['ckb', 'exact'] => $count.' هەڵبژاردەم دۆزییەوە کە لەگەڵ داواکارییەکەت دەگونجێن. بۆ وردەکاری پڕۆژەکە بکەرەوە.',
            default => 'هیچ هەڵبژاردەیەک هەموو مەرجەکان پڕ ناکاتەوە. ئەمانە '.$count.' نزیکترین هەڵبژاردەن و جیاوازییەکان لە هەر کارتێکدا دیارن.',
        };
    }

    private function resultTitle(string $locale, string $status): string
    {
        return match ([$locale, $status]) {
            ['ar', 'exact'] => 'الخيارات المناسبة',
            ['ar', 'near'] => 'أقرب البدائل',
            ['en', 'exact'] => 'Matching options',
            ['en', 'near'] => 'Closest alternatives',
            ['ckb', 'exact'] => 'هەڵبژاردە گونجاوەکان',
            default => 'نزیکترین جێگرەوەکان',
        };
    }

    private function resultSubtitle(string $locale, string $status): string
    {
        return match ([$locale, $status]) {
            ['ar', 'exact'] => 'النتائج مبنية على المشاريع والأسعار المنشورة بالموقع.',
            ['ar', 'near'] => 'ما غيّرنا طلبك بصمت؛ كل شرط غير متوفر مكتوب داخل البطاقة.',
            ['en', 'exact'] => 'Results use published projects and prices from the site.',
            ['en', 'near'] => 'Your request was not silently changed; every unmet condition is shown on the card.',
            ['ckb', 'exact'] => 'ئەنجامەکان لەسەر بنەمای پڕۆژە و نرخە بڵاوکراوەکانی ماڵپەڕن.',
            default => 'داواکارییەکەت بە نهێنی نەگۆڕدراوە؛ هەر مەرجێک بەردەست نەبێت لە کارتەكەدا نووسراوە.',
        };
    }

    /** @return array<string, mixed> */
    private function emptyResult(string $locale): array
    {
        $message = match ($locale) {
            'ar' => 'حالياً ماكو مشاريع منشورة نكدر نعرضها بثقة. جرّب مرة ثانية بعد تحديث بيانات المشاريع.',
            'en' => 'There are no published projects I can show confidently right now. Please try again after the project data is updated.',
            default => 'لە ئێستادا هیچ پڕۆژەیەکی بڵاوکراوە بەردەست نییە کە بە دڵنیایی پیشانت بدەم. دوای نوێکردنەوەی داتا دووبارە هەوڵ بدە.',
        };

        return [
            'status' => 'none',
            'message' => $message,
            'title' => $this->text($locale, 'no_results'),
            'subtitle' => '',
            'button_label' => $this->text($locale, 'open_project'),
            'items' => [],
            'locale' => $locale,
        ];
    }

    private function text(string $locale, string $key): string
    {
        $copy = [
            'ckb' => [
                'within_budget' => 'لە سنووری بودجەکەتدایە',
                'above_budget' => 'لە بودجەکەت زیاترە',
                'price_unavailable' => 'نرخی بڵاوکراوە بەردەست نییە',
                'type_match' => 'جۆری موڵکەکە لەگەڵ داواکارییەکەت دەگونجێت',
                'type_differs' => 'جۆری موڵکەکە جیاوازە',
                'area_match' => 'لە ناوچەی دڵخوازتدایە',
                'outside_area' => 'لە دەرەوەی ناوچەی دڵخوازتدایە',
                'school_unconfirmed' => 'نزیکی قوتابخانە پشتڕاست نەکراوەتەوە',
                'instalments_unconfirmed' => 'پلانی قیست لە داتاکاندا پشتڕاست نەکراوەتەوە',
                'matches_request' => 'لەگەڵ داواکارییەکەت دەگونجێت',
                'closest_alternative' => 'نزیکترین جێگرەوە',
                'open_project' => 'بینینی پڕۆژە',
                'no_results' => 'هیچ ئەنجامێک نییە',
            ],
            'ar' => [
                'within_budget' => 'ضمن ميزانيتك',
                'above_budget' => 'أعلى من الميزانية',
                'price_unavailable' => 'السعر المنشور غير متوفر',
                'type_match' => 'نوع العقار يطابق طلبك',
                'type_differs' => 'نوع العقار مختلف',
                'area_match' => 'ضمن المنطقة المطلوبة',
                'outside_area' => 'خارج المنطقة المطلوبة',
                'school_unconfirmed' => 'ما عدنا تأكيد عن مدرسة قريبة',
                'instalments_unconfirmed' => 'خطة الأقساط غير مؤكدة بالبيانات',
                'matches_request' => 'يطابق طلبك',
                'closest_alternative' => 'أقرب بديل',
                'open_project' => 'فتح المشروع',
                'no_results' => 'لا توجد نتائج',
            ],
            'en' => [
                'within_budget' => 'Within your budget',
                'above_budget' => 'Above budget',
                'price_unavailable' => 'Published price is unavailable',
                'type_match' => 'Property type matches',
                'type_differs' => 'Different property type',
                'area_match' => 'In your preferred area',
                'outside_area' => 'Outside your preferred area',
                'school_unconfirmed' => 'A nearby school is not confirmed',
                'instalments_unconfirmed' => 'An instalment plan is not confirmed in the data',
                'matches_request' => 'Matches your request',
                'closest_alternative' => 'Closest alternative',
                'open_project' => 'View project',
                'no_results' => 'No results',
            ],
        ];

        return $copy[$locale][$key] ?? $copy['ckb'][$key] ?? $key;
    }
}
