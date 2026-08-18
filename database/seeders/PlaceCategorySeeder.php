<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Geography\Enums\PlaceCategoryKey;
use App\Modules\Geography\Models\PlaceCategory;
use Illuminate\Database\Seeder;

/**
 * The 31 shipped place categories (spec 10.4).
 *
 * Outstanding since Step 2: the categories existed as an enum that later steps
 * reference by name, but nothing wrote them to the table the admin actually
 * edits, so a fresh install had an empty category list and no place could be
 * created.
 *
 * Marked `is_system` so they can be deactivated but never deleted — spec 10.4
 * lets an administrator add categories, and lifestyle matching in Step 4 looks
 * up "school" and "hospital" by key. Deleting one would break that lookup
 * silently.
 *
 * `firstOrCreate` on the key means a later deploy adds new categories without
 * discarding an administrator's icon, colour or radius choices.
 */
final class PlaceCategorySeeder extends Seeder
{
    /**
     * Sorani, Arabic and English names for the shipped set. Kept here rather
     * than read from the language files because a seeder must work before the
     * translation tables are populated.
     *
     * @var array<string, array{string, string, string}>
     */
    private const NAMES = [
        'school' => ['قوتابخانە', 'مدرسة', 'School'],
        'kindergarten' => ['باخچەی ساوایان', 'روضة أطفال', 'Kindergarten'],
        'university' => ['زانکۆ', 'جامعة', 'University'],
        'institute' => ['پەیمانگا', 'معهد', 'Institute'],
        'hospital' => ['نەخۆشخانە', 'مستشفى', 'Hospital'],
        'clinic' => ['کلینیک', 'عيادة', 'Clinic'],
        'pharmacy' => ['دەرمانخانە', 'صيدلية', 'Pharmacy'],
        'mall' => ['مۆڵ', 'مول', 'Mall'],
        'supermarket' => ['سوپەرمارکێت', 'سوبر ماركت', 'Supermarket'],
        'market' => ['بازاڕ', 'سوق', 'Market'],
        'restaurant' => ['چێشتخانە', 'مطعم', 'Restaurant'],
        'cafe' => ['کافێ', 'مقهى', 'Café'],
        'park' => ['پارک', 'حديقة', 'Park'],
        'sports_facility' => ['یاریگا', 'منشأة رياضية', 'Sports facility'],
        'mosque' => ['مزگەوت', 'مسجد', 'Mosque'],
        'church' => ['کڵێسا', 'كنيسة', 'Church'],
        'government_office' => ['فەرمانگەی حکومی', 'دائرة حكومية', 'Government office'],
        'police' => ['پۆلیس', 'شرطة', 'Police'],
        'fire_station' => ['بنکەی ئاگرکوژێنەوە', 'إطفاء', 'Fire station'],
        'bank' => ['بانک', 'مصرف', 'Bank'],
        'atm' => ['ئەی تی ئێم', 'صراف آلي', 'ATM'],
        'airport' => ['فڕۆکەخانە', 'مطار', 'Airport'],
        'bus_station' => ['وێستگەی پاس', 'كراج', 'Bus station'],
        'fuel_station' => ['بۆرییەخانە', 'محطة وقود', 'Fuel station'],
        'workplace' => ['شوێنی کار', 'مكان عمل', 'Workplace'],
        'industrial_zone' => ['ناوچەی پیشەسازی', 'منطقة صناعية', 'Industrial zone'],
        'landmark' => ['نیشانەی دیار', 'معلم', 'Landmark'],
        'hotel' => ['هۆتێل', 'فندق', 'Hotel'],
        'road_entrance' => ['دەروازەی ڕێگا', 'مدخل طريق', 'Road entrance'],
        'highway_access' => ['دەرچوونی ڕێگای سەرەکی', 'مدخل سريع', 'Highway access'],
        'planned_infrastructure' => ['ژێرخانی پلاندراو', 'بنى تحتية مخططة', 'Planned infrastructure'],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (PlaceCategoryKey::cases() as $category) {
            [$ckb, $ar, $en] = self::NAMES[$category->value];

            PlaceCategory::query()->firstOrCreate(
                ['key' => $category->value],
                [
                    'group' => $category->group(),
                    'name_ckb' => $ckb,
                    'name_ar' => $ar,
                    'name_en' => $en,
                    // Radius and weight come from the enum so the seeded rows
                    // and the ranking engine cannot disagree on day one.
                    'default_radius_m' => $category->defaultRadiusMetres(),
                    'amenity_weight' => $category->amenityWeight(),
                    'is_system' => true,
                    'is_active' => true,
                    'sort_order' => $order++,
                ],
            );
        }
    }
}
