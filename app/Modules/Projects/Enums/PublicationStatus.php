<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Editorial state (spec 12.3 "the admin may save a draft at any stage",
 * spec 37.2 exit criterion "admin creates and publishes project").
 */
enum PublicationStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    /**
     * Publication is reachable only from review or a previous unpublish.
     * A draft cannot jump straight to published — spec 12.3 lists Preview as
     * step 13 and Publication as step 14 for a reason.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Archived],
            self::InReview => [self::Draft, self::Published, self::Archived],
            self::Published => [self::Unpublished, self::Archived],
            self::Unpublished => [self::InReview, self::Published, self::Archived],
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return __('projects.publication_statuses.'.$this->value);
    }
}
