<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Branding\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Default admin-editable settings (spec 8).
 *
 * `updateOrCreate` on the key only — an existing value is never overwritten,
 * so re-running the seeder after an upgrade adds new keys without discarding
 * an administrator's choices.
 */
final class SiteSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // group, key, value, type, public, secret
            ['branding', 'branding.site_name', 'Mulkihawler', 'string', true, false],
            ['branding', 'branding.tagline_ckb', 'زانیاریی بازاڕی خانووبەرە لە هەولێر', 'string', true, false],
            ['branding', 'branding.color_brand', '15 62 89', 'string', true, false],
            ['branding', 'branding.color_accent', '201 162 39', 'string', true, false],
            ['branding', 'branding.color_surface', '250 250 249', 'string', true, false],
            ['branding', 'branding.color_ink', '23 23 23', 'string', true, false],
            ['branding', 'branding.dark_mode_enabled', '1', 'boolean', true, false],

            ['contact', 'contact.email', '', 'string', true, false],
            ['contact', 'contact.phone', '', 'string', true, false],
            ['contact', 'contact.address_ckb', '', 'string', true, false],

            ['display', 'display.default_currency', 'USD', 'string', true, false],
            ['display', 'display.numeral_system', 'latn', 'string', true, false],
            ['display', 'display.area_unit', 'sqm', 'string', true, false],
            ['display', 'display.date_format', 'Y-m-d', 'string', true, false],

            ['legal', 'legal.privacy_updated_at', '', 'string', true, false],
            ['legal', 'legal.terms_updated_at', '', 'string', true, false],

            ['seo', 'seo.default_title_ckb', 'مولکی هەولێر', 'string', true, false],
            ['seo', 'seo.default_description_ckb', '', 'string', true, false],
            ['seo', 'seo.indexing_enabled', '0', 'boolean', true, false],

            // Operational, not for the browser.
            ['ops', 'ops.maintenance_message_ckb', '', 'string', false, false],
            ['ops', 'ops.support_notification_email', '', 'string', false, true],
        ];

        foreach ($defaults as [$group, $key, $value, $type, $public, $secret]) {
            SiteSetting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'group' => $group,
                    'type' => $type,
                    'value' => $value,
                    'is_public' => $public,
                    'is_secret' => $secret,
                ],
            );
        }
    }
}
