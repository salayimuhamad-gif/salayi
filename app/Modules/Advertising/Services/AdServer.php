<?php

declare(strict_types=1);

namespace App\Modules\Advertising\Services;

use App\Modules\Advertising\Enums\AdSurface;
use App\Modules\Advertising\Models\AdCampaign;
use App\Modules\Advertising\Models\AdCreative;
use App\Modules\Advertising\Models\AdEvent;

/**
 * Selects and counts advertisements (File one §8.8, §8.9; File two §13).
 *
 * The class exists to keep one guarantee true: **an advertisement is chosen
 * here and nowhere else.** Ranking services never call into this namespace, and
 * this service never calls into ranking. §8.9 forbids sponsorship from altering
 * organic scores, and the only durable way to hold that is for the two never to
 * meet — a rule about "not boosting" would eventually be interpreted by
 * somebody who thought a small boost was in scope.
 *
 * Everything returned is labelled. §12.2 requires paid content to be
 * distinguishable, and the label travels with the creative rather than being
 * applied by whichever template happens to render it, because a template that
 * forgets is a disclosure failure the advertiser is not even aware of.
 */
final class AdServer
{
    /**
     * Advertisements for one surface, already filtered by cap and schedule.
     *
     * @return list<array<string, mixed>>
     */
    public function forSurface(
        AdSurface $surface,
        ?int $projectId = null,
        ?int $areaId = null,
        ?string $locale = null,
        int $limit = 2,
    ): array {
        if (! (bool) feature('advertising')) {
            // The flag is Super-Admin gated and commercially sensitive. An
            // empty array rather than an exception: a disabled ad system must
            // degrade to a page with no advertisements, not a broken page.
            return [];
        }

        $locale = $locale ?? app()->getLocale();

        $campaigns = AdCampaign::query()
            ->servable()
            ->where('placement', $surface->value)
            /*
             * A null target is a wildcard, so an un-narrowed campaign still
             * serves. Grouped, because an ungrouped OR here would match every
             * campaign in the table and serve an area-targeted advertisement
             * on every page in the platform.
             */
            ->where(fn ($q) => $q->whereNull('target_project_id')->orWhere('target_project_id', $projectId))
            ->where(fn ($q) => $q->whereNull('target_area_id')->orWhere('target_area_id', $areaId))
            ->where(fn ($q) => $q->whereNull('locale')->orWhere('locale', $locale))
            ->with(['creatives' => fn ($q) => $q->approved()])
            ->limit($limit * 3)
            ->get();

        $served = [];

        foreach ($campaigns as $campaign) {
            if ($campaign->hasReachedCap()) {
                continue;
            }

            // Prefer a creative in the reader's language; fall back to Sorani,
            // which is the authored language of this platform.
            $creative = $campaign->creatives->firstWhere('locale', $locale)
                ?? $campaign->creatives->firstWhere('locale', 'ckb')
                ?? $campaign->creatives->first();

            if (! $creative instanceof AdCreative) {
                continue;
            }

            $served[] = [
                'campaign_id' => $campaign->id,
                'creative_id' => $creative->id,
                'headline' => $creative->headline,
                'body' => $creative->body,
                'image_path' => $creative->image_path,
                'destination_url' => $creative->click_url,
                /*
                 * Always present, never optional — and NOT NULL in the schema,
                 * so a campaign cannot exist undisclosed. A caller cannot
                 * render one of these without the disclosure in hand.
                 */
                'disclosure' => $campaign->disclosure_label,
                'is_advertisement' => true,
            ];

            if (count($served) >= $limit) {
                break;
            }
        }

        return $served;
    }

    /**
     * Record an impression.
     *
     * One row per event. That costs storage and buys the only thing that
     * answers an advertiser disputing an invoice: when the campaign actually
     * ran, not merely how often.
     */
    public function recordImpression(int $campaignId, ?int $creativeId, ?string $viewerHash = null): void
    {
        $this->record($campaignId, $creativeId, 'impression', $viewerHash);
    }

    public function recordClick(int $campaignId, ?int $creativeId, ?string $viewerHash = null): void
    {
        $this->record($campaignId, $creativeId, 'click', $viewerHash);
    }

    private function record(int $campaignId, ?int $creativeId, string $type, ?string $viewerHash): void
    {
        AdEvent::query()->create([
            'ad_campaign_id' => $campaignId,
            'ad_creative_id' => $creativeId,
            'event_type' => $type,
            // Hashed at the point of writing, never raw. An impression log is
            // not a reason to retain an identifiable browsing record.
            'viewer_hash' => $viewerHash === null ? null : hash('sha256', $viewerHash),
            'locale' => app()->getLocale(),
            'occurred_at' => now(),
        ]);
    }

    /**
     * Whether a campaign may still serve.
     *
     * Exposed so an admin screen can explain WHY a live campaign is showing
     * nothing — "the daily cap is reached" is actionable; a blank slot is not.
     */
    public function servingState(AdCampaign $campaign): string
    {
        if (! $campaign->is_approved || $campaign->approved_at === null) {
            return 'awaiting_approval';
        }

        if ($campaign->status !== 'active') {
            return $campaign->status;
        }

        if ($campaign->hasReachedCap()) {
            return 'cap_reached';
        }

        return 'serving';
    }
}
