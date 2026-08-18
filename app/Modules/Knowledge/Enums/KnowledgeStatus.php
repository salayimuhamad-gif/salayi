<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Enums;

/**
 * Knowledge event workflow (spec 21.3).
 *
 * The reason this enum carries `isAiUsable()` rather than leaving it to the
 * retrieval layer: spec 17.5 forbids AI using "draft or expired knowledge", and
 * spec 21.2 makes AI-usage permission a per-event field. Two independent
 * conditions must both hold, and encoding one of them here means the retrieval
 * guard cannot accidentally admit a draft by checking only the permission flag.
 */
enum KnowledgeStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Approved = 'approved';
    case Published = 'published';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Review, self::Archived],
            self::Review => [self::Approved, self::Rejected, self::Draft],
            /*
             * Expired is reachable from Approved as well as Published.
             *
             * Omitted originally, and that was a gap rather than a decision:
             * aiUsable() already treats an approved event past its expiry as
             * unusable, so the state and the behaviour disagreed. An approved
             * event that has passed its expiry IS expired, and without this
             * the scheduled sweep can never say so — leaving the status
             * permanently claiming something the gate already denies.
             */
            self::Approved => [self::Published, self::Review, self::Archived, self::Expired],
            self::Published => [self::Expired, self::Archived, self::Review],
            self::Expired => [self::Review, self::Archived],
            self::Rejected => [self::Draft, self::Archived],
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    /**
     * Statuses from which an event may inform an AI answer.
     *
     * Approved counts as well as Published: an approved event has passed human
     * review and is factually usable even before its publication date. Draft
     * and Expired never do (spec 17.5).
     */
    public function isAiUsable(): bool
    {
        return in_array($this, [self::Approved, self::Published], true);
    }

    public function label(): string
    {
        return __('knowledge.statuses.'.$this->value);
    }
}
