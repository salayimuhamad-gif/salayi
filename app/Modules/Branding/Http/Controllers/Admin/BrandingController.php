<?php

declare(strict_types=1);

namespace App\Modules\Branding\Http\Controllers\Admin;

use App\Modules\Branding\Models\BrandingAsset;
use App\Modules\Core\Contracts\SettingsRepository;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branding and PWA identity (spec 8, 9.2).
 *
 * Colours are stored as space-separated RGB triples rather than hex, because
 * the stylesheet consumes them through `rgb(var(--mh-brand) / <alpha-value>)`
 * so Tailwind can vary opacity on an admin-chosen colour. Storing hex would
 * make every translucent surface impossible without a runtime conversion.
 */
final class BrandingController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Admin/Branding', [
            'settings' => $this->settings->group('branding'),
            'typography' => $this->settings->group('typography'),
            'assets' => BrandingAsset::query()
                ->where('is_current', true)
                ->get(['slot', 'path', 'disk', 'width', 'height', 'version'])
                ->keyBy('slot'),
            'slots' => BrandingAsset::SLOTS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rgb = ['regex:/^\d{1,3} \d{1,3} \d{1,3}$/'];

        /*
         * The Inertia form submits FLAT keys with literal dots
         * ("branding.site_name"), while dotted validation rules address
         * NESTED data — so every save of this form 422'd with "required" on
         * fields that were filled, ever since it shipped (no test covered
         * the save). Undotting the input before validation restores the
         * intended contract in one place: rules match, `validated` comes
         * back nested, and re-dotting it yields exactly the storage keys.
         * Error message keys stay dotted, so the form's error bindings are
         * untouched. Pinned by PublicTypographyTest.
         */
        $validated = Validator::make(Arr::undot($request->all()), [
            'branding.site_name' => ['required', 'string', 'max:120'],
            'branding.tagline_ckb' => ['nullable', 'string', 'max:200'],
            'branding.tagline_ar' => ['nullable', 'string', 'max:200'],
            'branding.tagline_en' => ['nullable', 'string', 'max:200'],
            'branding.color_brand' => array_merge(['required'], $rgb),
            'branding.color_brand_soft' => array_merge(['nullable'], $rgb),
            'branding.color_brand_strong' => array_merge(['nullable'], $rgb),
            'branding.color_accent' => array_merge(['required'], $rgb),
            'branding.color_surface' => array_merge(['nullable'], $rgb),
            'branding.color_ink' => array_merge(['nullable'], $rgb),
            'branding.dark_mode_enabled' => ['boolean'],
            'branding.pwa_name' => ['nullable', 'string', 'max:45'],
            'branding.pwa_short_name' => ['nullable', 'string', 'max:12'],
            'branding.pwa_theme_color' => ['nullable', 'string', 'max:9'],
            /*
             * Public typography (Wave 2B §7). Same generic key-value store,
             * same permission, same audit — no schema change. Every value is
             * a closed enum: the Blade token block maps the choice to a real,
             * bundled font stack server-side, so no free-form string can ever
             * reach a stylesheet. The scale is a bounded percentage; the
             * per-script faces keep Kurdish and Arabic on Arabic-script
             * fonts — the Latin list is never offered for them.
             */
            'typography.font_latin' => ['nullable', 'in:outfit,noto_sans,system'],
            'typography.font_ckb' => ['nullable', 'in:k24,kufi'],
            'typography.font_ar' => ['nullable', 'in:noto_sans_arabic,kufi'],
            'typography.public_scale' => ['nullable', 'in:90,95,100,105,110,115,120'],
        ])->validate();

        $before = [
            'branding' => $this->settings->group('branding'),
            'typography' => $this->settings->group('typography'),
        ];

        foreach (Arr::dot($validated) as $key => $value) {
            $this->settings->set($key, $value, $request->user()?->id);
        }

        $this->audit->record('branding.updated', null, [
            'before' => $before,
            'after' => [
                'branding' => $this->settings->group('branding'),
                'typography' => $this->settings->group('typography'),
            ],
        ]);

        return back()->with('success', __('app.states.saved'));
    }

    /**
     * Spec 8: "All file uploads must be validated and versioned."
     *
     * A new upload is a new version with `is_current` moved, never an
     * overwrite — reverting a logo change should be one click and must not
     * require re-uploading a file nobody kept.
     */
    public function upload(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slot' => ['required', 'string', 'in:'.implode(',', BrandingAsset::SLOTS)],
            'file' => [
                'required', 'file',
                'mimetypes:'.implode(',', (array) config('filesystems.uploads.image_mimes')),
                'max:'.(int) config('filesystems.uploads.max_image_kb'),
            ],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        // Extension blocklist applied regardless of reported MIME (spec 30.1).
        if (in_array($extension, (array) config('filesystems.uploads.blocked_extensions'), true)) {
            return back()->withErrors(['file' => __('app.states.error')]);
        }

        $version = (int) BrandingAsset::query()->where('slot', $validated['slot'])->max('version') + 1;
        $path = $file->store('branding/'.$validated['slot'], 'public');

        BrandingAsset::query()->where('slot', $validated['slot'])->update(['is_current' => false]);

        $asset = BrandingAsset::query()->create([
            'slot' => $validated['slot'],
            'version' => $version,
            'is_current' => true,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', Storage::disk('public')->path($path)),
            'uploaded_by' => $request->user()?->id,
        ]);

        $this->audit->record('branding.asset.uploaded', $asset, ['slot' => $validated['slot'], 'version' => $version]);

        return back()->with('success', __('app.states.saved'));
    }
}
