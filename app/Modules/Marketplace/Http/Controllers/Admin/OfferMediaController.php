<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Admin;

use App\Modules\Core\Support\MediaUploader;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\OfferMedia;
use App\Modules\Marketplace\Services\OfferMediaService;
use App\Modules\Marketplace\Support\OfferScope;
use App\Modules\Projects\Exceptions\DuplicateMediaException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offer media (spec 19.4).
 *
 * The difference from project media is moderation, and it is not incidental.
 * Project images are uploaded by staff; offer images are uploaded by companies
 * about property they are selling. An unmoderated one is a photograph of a
 * different building, a competitor's render, or something that should not be on
 * the site at all — and it appears beside a price with the platform's name
 * above it.
 *
 * So `offer_media.moderation_status` gates public display, and nothing reaches
 * a buyer without a human having looked at it.
 */
final class OfferMediaController extends Controller
{
    public function __construct(
        private readonly OfferMediaService $media,
        private readonly MediaUploader $uploader,
    ) {}

    public function index(Request $request, Offer $offer): Response
    {
        OfferScope::authorise($request, $offer);

        return Inertia::render('Admin/Offers/Media', [
            'offer' => [
                'id' => $offer->id,
                'title' => $offer->title_ckb,
                'status' => $offer->status->value,
            ],
            'media' => $this->collection($offer),
            'can' => ['moderate' => $request->user()?->hasPermission('marketplace.offers.moderate') ?? false],
        ]);
    }

    /** The pending queue across every offer, so nothing waits unseen. */
    public function queue(Request $request): Response
    {
        // Defence in depth: the route is platform-only, and so is this.
        abort_unless(OfferScope::isModerator($request), 403);

        $pending = OfferMedia::query()
            ->where('moderation_status', 'pending')
            // A row awaiting deletion must not appear in the moderation queue:
            // approving it would resurrect an image already on its way out.
            ->where('cleanup_pending', false)
            ->with('offer:id,title_ckb,company_id')
            ->orderBy('created_at')
            ->limit(60)
            ->get()
            ->map(fn (OfferMedia $m): array => [
                'id' => $m->id,
                'offer_id' => $m->offer_id,
                'offer_title' => $m->offer?->title_ckb,
                'url' => $m->url(),
                'alt_ckb' => $m->alt_ckb,
                'original_name' => $m->original_name,
            ])->all();

        return Inertia::render('Admin/Offers/MediaQueue', [
            'pending' => $pending,
            'can' => ['moderate' => $request->user()?->hasPermission('marketplace.offers.moderate') ?? false],
        ]);
    }

    public function store(Request $request, Offer $offer): RedirectResponse
    {
        OfferScope::authorise($request, $offer);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('filesystems.uploads.max_image_kb')],
            'alt_ckb' => ['nullable', 'string', 'max:191'],
            'alt_ar' => ['nullable', 'string', 'max:191'],
            'alt_en' => ['nullable', 'string', 'max:191'],
        ]);

        $result = $this->uploader->storeImage($request->file('file'), 'offers/'.$offer->id);

        if (! $result['ok']) {
            return back()->withErrors(['file' => __('media.errors.'.$result['reason'])]);
        }

        /*
         * ONE writer. The duplicate check, sort order, cover invariant,
         * moderation state and audit all happen inside the service under the
         * offer row lock — done here they were unlocked, and two concurrent
         * uploads both took the same position.
         */
        try {
            $media = $this->media->store($offer, $result, [
                'kind' => 'image',
                'alt_ckb' => $request->string('alt_ckb')->toString() ?: null,
                'alt_ar' => $request->string('alt_ar')->toString() ?: null,
                'alt_en' => $request->string('alt_en')->toString() ?: null,
            ]);
        } catch (DuplicateMediaException) {
            return back()->withErrors(['file' => __('media.errors.duplicate')]);
        }

        if ($media === null) {
            return back()->withErrors(['file' => __('media.errors.upload_failed')]);
        }

        return back()->with('success', __('media.pending_review'));
    }

    public function moderate(Request $request, Offer $offer, int $media): RedirectResponse
    {
        abort_unless(OfferScope::isModerator($request), 403);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Approval is the only route to becoming a cover, so the invariant is
        // re-run inside the same transaction as the decision.
        if (! $this->media->moderate($offer, $media, $validated['decision'], $validated['reason'] ?? null)) {
            return back()->withErrors(['media' => __('media.errors.not_found')]);
        }

        return back()->with('success', __('app.states.saved'));
    }

    public function destroy(Request $request, Offer $offer, int $media): RedirectResponse
    {
        OfferScope::authorise($request, $offer);

        /*
         * Bytes go AFTER the commit, inside the service. Removing them here
         * meant a rolled-back transaction restored the row while the file
         * stayed deleted — a gallery entry pointing at nothing.
         */
        $item = OfferMedia::query()
            ->where('offer_id', $offer->id)
            // A row awaiting deletion must not be deletable again.
            ->where('cleanup_pending', false)
            ->whereKey($media)
            ->first();

        if ($item === null) {
            return back()->withErrors(['media' => __('media.errors.not_found')]);
        }

        if (! $this->media->delete($offer, (int) $item->id)) {
            return back()->withErrors(['media' => __('media.errors.cleanup_failed')]);
        }

        return back()->with('success', __('app.states.saved'));
    }

    /** @return list<array<string, mixed>> */
    private function collection(Offer $offer): array
    {
        return OfferMedia::query()
            ->where('offer_id', $offer->id)
            // Rows awaiting deletion are on their way out.
            ->where('cleanup_pending', false)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (OfferMedia $m): array => [
                'id' => $m->id,
                'url' => $m->url(),
                'alt_ckb' => $m->alt_ckb,
                'is_cover' => $m->is_cover,
                'moderation_status' => $m->moderation_status,
                'missing_alt' => trim((string) $m->alt_ckb) === '',
            ])->all();
    }
}
