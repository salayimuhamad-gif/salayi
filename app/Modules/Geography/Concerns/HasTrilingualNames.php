<?php

declare(strict_types=1);

namespace App\Modules\Geography\Concerns;

use App\Modules\Localization\Support\SoraniText;
use Illuminate\Support\Facades\Schema;

/**
 * Trilingual name resolution (spec 7.1, Step 2 exit criterion "three languages
 * display").
 *
 * The fallback chain is ckb -> en -> ar, NOT the request locale's own language
 * family. Sorani is the authoring language, so a missing ar translation should
 * fall back to the name that definitely exists rather than to a blank.
 *
 * Falling back is also RECORDED, not silent: `nameIsFallback()` lets the admin
 * data-quality view list exactly which records are missing which translation,
 * which is what makes the translation backlog visible instead of invisible.
 */
trait HasTrilingualNames
{
    public function name(?string $locale = null): string
    {
        return $this->trilingual('name', $locale);
    }

    public function description(?string $locale = null): ?string
    {
        $value = $this->trilingual('description', $locale);

        return $value === '' ? null : $value;
    }

    /**
     * Trilingual address, for models that carry `address_{locale}` columns.
     *
     * Same fallback chain as name and description. Returns null rather than an
     * empty string on a model without the columns, so a public profile renders
     * "no address recorded" instead of a blank line that reads as a rendering
     * fault.
     */
    public function address(?string $locale = null): ?string
    {
        $value = $this->trilingual('address', $locale);

        return $value === '' ? null : $value;
    }

    public function nameIsFallback(?string $locale = null): bool
    {
        $locale ??= app()->getLocale();
        $column = 'name_'.$locale;

        return blank($this->{$column} ?? null);
    }

    /** @return list<string> locales with no value for this field */
    public function missingTranslations(string $field = 'name'): array
    {
        $missing = [];

        foreach (['ckb', 'ar', 'en'] as $locale) {
            if (blank($this->{$field.'_'.$locale} ?? null)) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    private function trilingual(string $field, ?string $locale): string
    {
        $locale ??= app()->getLocale();

        foreach ([$locale, 'ckb', 'en', 'ar'] as $candidate) {
            $value = $this->{$field.'_'.$candidate} ?? null;

            if (filled($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /** Keep the derived search key in step with the Sorani name. */
    public function syncSearchKey(): void
    {
        $parts = [$this->name_ckb ?? '', $this->name_ar ?? '', $this->name_en ?? ''];

        /** @var list<string>|null $aliases */
        $aliases = $this->aliases ?? null;

        if (is_array($aliases)) {
            $parts = array_merge($parts, $aliases);
        }

        /*
         * `place_categories` has no `search_key`, and PlaceCategory uses this
         * trait for its name fallbacks only. Writing the attribute anyway would
         * fail on save for any model that adopts the trait without the column.
         */
        if (! array_key_exists('search_key', $this->getAttributes())
            && ! Schema::hasColumn($this->getTable(), 'search_key')) {
            return;
        }

        $this->setAttribute('search_key', SoraniText::searchKey(implode(' ', array_filter($parts))));
    }
}
