<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\ProjectType;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Validation localization — the guard for a defect that shipped.
 *
 * The app's locale AND fallback are ckb, and no locale carried a
 * validation.php at all, so every builtin rule failure rendered as its raw
 * key: the admin creating the first project was told `validation.required`
 * and `validation.regex`, verbatim. Separately, the wizard's project-type
 * select translated its options through a key group that never existed
 * (`projects.project_types.*` instead of `projects.types.*`) and rendered
 * raw keys as option labels.
 *
 * These tests pin the lang-side halves of both defects: the validation
 * message set exists, agrees across locales, resolves to human text for
 * every rule the admin/public FormRequests actually use — and every project
 * enum case has a label in every locale.
 */
final class ValidationLocalizationTest extends TestCase
{
    private const LOCALES = ['ckb', 'ar', 'en'];

    /**
     * Every rule reachable through the admin and public FormRequests and the
     * inline controller validations. A rule failing with no message here
     * renders its raw `validation.*` key to the person at the form.
     */
    private const REACHABLE_RULES = [
        'accepted', 'array', 'boolean', 'confirmed', 'current_password',
        'date', 'email', 'enum', 'exists', 'file', 'gte.numeric', 'image',
        'in', 'integer', 'max.numeric', 'max.string', 'mimes', 'mimetypes',
        'min.numeric', 'min.string', 'between.numeric', 'between.string',
        'numeric', 'regex', 'required', 'required_if', 'required_with',
        'required_without', 'size.string', 'string', 'unique', 'uploaded',
        'url',
    ];

    public function test_every_locale_ships_the_same_validation_message_keys(): void
    {
        $sets = [];

        foreach (self::LOCALES as $locale) {
            $path = lang_path($locale.'/validation.php');
            $this->assertFileExists($path, "lang/{$locale}/validation.php is missing — raw validation.* keys leak in {$locale}");

            $messages = require $path;
            $sets[$locale] = $this->flattenKeys($messages);
        }

        foreach (['ar', 'en'] as $locale) {
            $this->assertSame(
                $sets['ckb'],
                $sets[$locale],
                "lang/{$locale}/validation.php keys diverge from ckb — the missing keys leak raw in whichever locale lacks them",
            );
        }
    }

    public function test_every_reachable_rule_resolves_to_human_text_in_every_locale(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (self::REACHABLE_RULES as $rule) {
                $key = 'validation.'.$rule;
                $resolved = trans($key, [], $locale);

                $this->assertIsString($resolved);
                $this->assertNotSame(
                    $key,
                    $resolved,
                    "validation.{$rule} does not resolve in {$locale} — it would render as the raw key",
                );
            }
        }
    }

    public function test_a_failing_validator_speaks_kurdish_not_keys(): void
    {
        app()->setLocale('ckb');

        $validator = Validator::make(
            ['slug' => 'https://myhawler.com/'],
            [
                'name_ckb' => ['required'],
                'slug' => ['regex:/^[\p{L}\p{N}\-]+$/u'],
            ],
        );

        $messages = $validator->errors()->all();

        $this->assertCount(2, $messages);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString(
                'validation.',
                $message,
                'a validator message leaked a raw key: '.$message,
            );
        }

        // The exact production reproduction: a URL pasted into the slug field
        // must fail as a FORMAT problem stated in Sorani.
        $this->assertStringContainsString('شێوازی', (string) $validator->errors()->first('slug'));
    }

    public function test_every_project_enum_case_is_labelled_in_every_locale(): void
    {
        $groups = [
            'projects.types' => ProjectType::cases(),
            'projects.construction_statuses' => ConstructionStatus::cases(),
            'projects.delivery_statuses' => DeliveryStatus::cases(),
        ];

        foreach (self::LOCALES as $locale) {
            foreach ($groups as $prefix => $cases) {
                foreach ($cases as $case) {
                    $key = $prefix.'.'.$case->value;
                    $resolved = trans($key, [], $locale);

                    $this->assertNotSame(
                        $key,
                        $resolved,
                        "{$key} has no {$locale} label — a select or card would render the raw key",
                    );
                }
            }
        }
    }

    /**
     * Flatten nested message arrays ('max' => ['string' => …]) into dotted
     * keys, dropping the free-form custom/attributes payloads whose CONTENT
     * legitimately differs per locale — their key sets are compared too,
     * but attribute names are product wording, not rule coverage.
     *
     * @param array<string, mixed> $messages
     * @return list<string>
     */
    private function flattenKeys(array $messages, string $prefix = ''): array
    {
        $keys = [];

        foreach ($messages as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (in_array($dotted, ['custom', 'attributes'], true)) {
                $keys[] = $dotted;

                continue;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $dotted));

                continue;
            }

            $keys[] = $dotted;
        }

        sort($keys);

        return $keys;
    }
}
