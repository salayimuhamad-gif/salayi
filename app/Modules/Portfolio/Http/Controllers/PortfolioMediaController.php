<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Http\Controllers;

use App\Modules\Portfolio\Models\PortfolioMedia;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Services\PortfolioMediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload, stream and delete portfolio media (correction §6.8).
 *
 * Every action resolves BOTH the property and the media row through the
 * session owner — a stranger's id, for either, is a 404 that does not even
 * confirm existence. The download is a STREAM through this authorisation,
 * never a public URL: the files live on the private disk, and `no-store`
 * keeps the bytes out of shared caches, because a home's photographs and
 * ownership documents are exactly the class of thing §7.11 names.
 */
final class PortfolioMediaController extends Controller
{
    public function __construct(private readonly PortfolioMediaService $media) {}

    public function store(Request $request, int $property): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        $model = $this->ownedProperty($request)->findOrFail($property);

        $this->media->store($model, $request->file('file'));

        return redirect(localized_route('portfolio.show', ['property' => $model->id]))
            ->with('success', __('portfolio.media.added'));
    }

    public function download(Request $request, int $property, int $media): StreamedResponse
    {
        $row = $this->ownedMedia($request, $property, $media);

        abort_unless(Storage::disk($row->disk)->exists($row->path), 404);

        $disposition = $row->kind === 'image' ? 'inline' : 'attachment';

        return Storage::disk($row->disk)->response($row->path, $row->original_name, [
            'Content-Type' => $row->mime,
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $disposition,
                addcslashes($row->original_name, '"\\'),
            ),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, int $property, int $media): RedirectResponse
    {
        $row = $this->deletableMedia($request, $property, $media);

        $this->media->delete($row);

        return redirect(localized_route('portfolio.show', ['property' => $property]))
            ->with('success', __('portfolio.media.removed'));
    }

    /** @return Builder<PortfolioProperty> */
    private function ownedProperty(Request $request): Builder
    {
        return PortfolioProperty::query()->where('user_id', $request->user()->id);
    }

    /**
     * The media row, through the owner AND the property it was addressed
     * under — a valid media id under someone else's property id is as much
     * a 404 as a foreign one.
     */
    private function ownedMedia(Request $request, int $property, int $media): PortfolioMedia
    {
        $model = $this->ownedProperty($request)->findOrFail($property);

        /*
         * v4 BLOCKER 8: `readyMedia()`, not `media()`. A staging or
         * orphaned row must be unreachable through the download and delete
         * routes — its bytes are not at the path it names, so streaming it
         * would 404 mid-response and deleting it through the user path
         * would race the sweeper that owns it.
         */
        return $model->readyMedia()->whereKey($media)->firstOrFail();
    }

    /**
     * The media row DELETE may address (v5 correction).
     *
     * v4 routed destroy through {@see ownedMedia()}, whose
     * `whereNull('deletion_pending_at')` made the deletion-pending state a
     * dead end: the first failed removal marked the row pending, and the
     * retry the design promised then 404'd against its own filter — the
     * row was unreachable through the only path that could finish it.
     *
     * Delete therefore admits READY rows whether or not a deletion is
     * pending: retrying a pending deletion IS the feature. What it still
     * refuses, deliberately, is a STAGING row — those belong to the upload
     * pipeline and its sweeper, exactly as the v4 rationale said — and
     * download keeps the strict resolution, because streaming a file whose
     * removal already half-succeeded is the mid-response 404 that rationale
     * exists to prevent.
     */
    private function deletableMedia(Request $request, int $property, int $media): PortfolioMedia
    {
        $model = $this->ownedProperty($request)->findOrFail($property);

        return $model->media()
            ->where('status', PortfolioMedia::STATUS_READY)
            ->whereKey($media)
            ->firstOrFail();
    }
}
