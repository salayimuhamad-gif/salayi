<?php

declare(strict_types=1);

namespace App\Modules\Companies\Enums;

/**
 * The lifecycle of a company↔project association (spec 11.2).
 *
 * `is_approved` alone cannot express this. It is false for pending, rejected
 * AND revoked, and only one of those should still permit editing — so a single
 * boolean made a rejected association indistinguishable from one awaiting
 * review, and both were treated as editable.
 */
enum AssociationManagementStatus: string
{
    /**
     * Created through the Wizard by the acting company, awaiting review.
     *
     * Editable BY ITS CREATOR ONLY, and only when provenance proves it: the
     * company must be able to finish the project it just entered, without
     * "pending" becoming a way for anyone to claim any project.
     */
    case Pending = 'pending';

    /** Reviewed and active. Editable within its date window. */
    case Approved = 'approved';

    /** Reviewed and refused. No access. */
    case Rejected = 'rejected';

    /** Was approved; withdrawn since. No access. */
    case Revoked = 'revoked';

    /**
     * Pre-dates provenance tracking.
     *
     * A legacy `is_approved = false` row carries no evidence of who created it
     * or why. Defaulting those to `pending` would hand management rights to
     * every unapproved association a database happens to contain — so they
     * land here instead, which grants nothing until somebody reviews them.
     */
    case LegacyReview = 'legacy_review';

    /** Replaced by a newer association for the same company and project. */
    case Superseded = 'superseded';

    /**
     * Statuses that can EVER confer management rights.
     *
     * Pending is included here but is not sufficient on its own — see
     * ProjectScope, which additionally requires draft provenance.
     *
     * @return list<string>
     */
    public static function manageableValues(): array
    {
        return [self::Pending->value, self::Approved->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
