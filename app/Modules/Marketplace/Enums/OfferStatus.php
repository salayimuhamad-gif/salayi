<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Enums;

/**
 * The eleven-state offer workflow (spec 19.3).
 *
 * Modelled as an explicit transition graph rather than a status string, because
 * spec 37.4 requires "offer has moderation" and moderation is only meaningful
 * if the illegal transitions are actually impossible. The one that matters
 * commercially: a company cannot move its own offer from Draft to Published.
 * Every path to Published passes through Approved, which only a moderator can
 * set.
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Paused = 'paused';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Archived],
            self::Submitted => [self::UnderReview, self::Rejected, self::Draft],
            self::UnderReview => [self::Approved, self::ChangesRequested, self::Rejected],
            self::ChangesRequested => [self::Submitted, self::Draft, self::Archived],
            self::Approved => [self::Scheduled, self::Published, self::Rejected, self::Archived],
            self::Scheduled => [self::Published, self::Approved, self::Archived],
            self::Published => [self::Paused, self::Expired, self::Archived],
            self::Paused => [self::Published, self::Expired, self::Archived],
            self::Expired => [self::Draft, self::Archived],
            self::Rejected => [self::Draft, self::Archived],
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Only a moderator may set these (spec 19.3, 37.4). */
    public function requiresModerator(): bool
    {
        return in_array($this, [
            self::UnderReview, self::Approved, self::ChangesRequested, self::Rejected,
        ], true);
    }

    /** States a company may set on its own offer. */
    public function isCompanySettable(): bool
    {
        return ! $this->requiresModerator();
    }

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    /** States from which an offer is still in the moderation pipeline. */
    public function isPending(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::ChangesRequested], true);
    }

    public function label(): string
    {
        return __('marketplace.statuses.'.$this->value);
    }
}
