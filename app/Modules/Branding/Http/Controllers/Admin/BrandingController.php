<?php

declare(strict_types=1);

namespace App\Modules\Branding\Http\Controllers\Admin;

use App\Modules\Branding\Models\BrandingAsset;
use App\Modules\Core\Contracts\SettingsRepository;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
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

        $validated = $request->validate([
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
        ]);

        $before = $this->settings->group('branding');

        foreach ($validated as $key => $value) {
            $this->settings->set($key, $value, $request->user()?->id);
        }

        $this->audit->record('branding.updated', null, [
            'before' => $before,
            'after' => $this->settings->group('branding'),
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
