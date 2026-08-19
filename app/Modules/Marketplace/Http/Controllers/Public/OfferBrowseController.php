<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Public;

use App\Modules\Core\Support\LocalizedRoutes;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\OfferMedia;
use App\Modules\Marketplace\Services\OfferRanker;
use App\Modules\Marketplace\Services\OfferScorer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public offers page (spec 19.5).
 *
 * The reason this controller exists in the shape it does: OfferRanker returns
 * two separate lists and no merged one, so this cannot flatten them even by
 * accident. Organic and sponsored reach the browser as distinct collections and
 * the page renders them as distinct sections.
 *
 * Spec 19.5: "Organic and sponsored results must be visually distinguished."
 * Spec 18.4: "Ads must never silently influence independent AI scoring."
 * Spec 17.5: "Never rank sponsored content above better independent results."
 */
final class OfferBrowseController extends Controller
{
    public function __construct(
        private readonly OfferRanker $ranker,
        private readonly OfferScorer $scorer,
    ) {}

    public function index(Request $request): Response
    {
        $preferences = [
            'budget_max' => $request->string('budget')->toString() ?: null,
            'property_type' => $request->string('type')->toString() ?: null,
            'area_id' => $request->integer('area') ?: null,
        ];

        $offers = Offer::query()
            ->published()
            ->when($preferences['property_type'] !== null,
                fn ($q) => $q->where('property_type', $preferences['property_type']))
            ->when($preferences['area_id'] !== null,
                fn ($q) => $q->where('area_id', $preferences['area_id']))
            ->when($request->string('q')->toString() !== '', fn ($q) => $q->where(
                'search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%',
            ))
            ->with(['company:id,name_ckb,name_ar,name_en,verification_status,median_response_minutes',
                'project:id,name_ckb,name_ar,name_en',
                'area:id,name_ckb,name_ar,name_en'])
            ->limit(120)
            ->get();

        $ranked = $this->ranker->rank($offers->map(fn (Offer $offer): array => array_merge(
            ['offer_id' => $offer->id],
            // Merit components, computed without reference to sponsorship.
            $this->scorer->components($offer, $preferences),
            [
                'is_sponsored' => $offer->is_sponsored,
                'disclosure_label' => $offer->disclosure_label,
                'sponsor_bid' => $offer->sponsor_bid,
            ],
        ))->all());

        $byId = $offers->keyBy('id');

        return Inertia::render('Public/Offers/Index', [
            // Two collections, never one. The template cannot interleave what
            // it is not given together.
            'organic' => array_map(
                fn (array $row): array => $this->present($byId[$row['offer_id']]) + ['rank' => $row['rank']],
                $ranked['organic'],
            ),
            'sponsored' => array_map(
                fn (array $row): array => $this->present($byId[$row['offer_id']]) + [
                    'rank' => $row['rank'],
                    'disclosure_label' => $row['disclosure_label'],
                ],
                $ranked['sponsored'],
            ),
            'counts' => [
                'organic' => $ranked['organic_count'],
                'sponsored' => $ranked['sponsored_count'],
            ],
            'filters' => [
                'q' => $request->string('q')->toString(),
                'type' => $preferences['property_type'],
                'budget' => $preferences['budget_max'],
            ],
            'seo' => [
                'title' => __('marketplace.public.title'),
                'canonical' => LocalizedRoutes::canonical('/offers'),
                'alternates' => LocalizedRoutes::alternates('/offers'),
            ],
        ]);
    }

    public function show(Request $request, string $publicId): Response
    {
        $offer = Offer::query()
            ->where('public_id', $publicId)
            ->with(['company', 'project:id,name_ckb,name_ar,name_en,slug', 'area:id,name_ckb,name_ar,name_en'])
            ->firstOrFail();

        // A moderated-but-unpublished offer does not exist publicly. A 403
        // would confirm the id and leak the pipeline.
        abort_unless($offer->status === OfferStatus::Published, 404);

        return Inertia::render('Public/Offers/Show', [
            'offer' => $this->present($offer) + [
                'description' => $offer->description_ckb,
                'terms' => $offer->terms,
                'contact_method' => $offer->contact_method,
                'project_slug' => $offer->project?->slug,
            ],
            'seo' => [
                'title' => $offer->title_ckb,
                'canonical' => LocalizedRoutes::canonical('/offers/'.$offer->public_id),
                'alternates' => LocalizedRoutes::alternates('/offers/'.$offer->public_id),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Offer $offer): array
    {
        return [
            'public_id' => $offer->public_id,
            'title' => $offer->title_ckb,
            'offer_type' => $offer->offer_type,
            'property_type' => $offer->property_type,
            'price' => $offer->price,
            'currency' => $offer->currency,
            'size_sqm' => $offer->size_sqm,
            'rooms' => $offer->rooms,
            'area' => $offer->area?->name(),
            'project' => $offer->project?->name(),
            'company' => $offer->company?->name(),
            'company_verified' => $offer->company?->isVerified() ?? false,
            // An approximate location is labelled as approximate rather than
            // rendered as a precise pin (spec 19.4).
            'location_precision' => $offer->location_precision,
            'published_at' => $offer->published_at?->toDateString(),
            'is_sponsored' => $offer->is_sponsored,
            // Approved images only. An unmoderated photograph beside a price
            // with the platform's name above it is the risk moderation exists
            // to prevent.
            'images' => OfferMedia::query()
                ->where('offer_id', $offer->id)
            // Rows awaiting deletion are on their way out.
                ->where('cleanup_pending', false)
                ->where('moderation_status', 'approved')
                ->orderByDesc('is_cover')
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (OfferMedia $m): array => [
                    'url' => $m->url(),
                    'alt' => $m->alt_ckb,
                ])->all(),
        ];
    }
}
