<?php

declare(strict_types=1);

namespace App\Modules\Companies\Support;

use App\Modules\Companies\Enums\AssociationManagementStatus;

/**
 * The one lifecycle validator (spec 11.2).
 *
 * The model's guard required `approved_at` for approved but said nothing about
 * `rejected_at` or `revoked_at` — while the database CHECK required both. So
 * Eloquent accepted a row the database would reject, and the failure surfaced
 * as a driver error at commit time rather than a field error where somebody
 * could fix it. Two validators that disagree are worse than one that is
 * slightly wrong, because neither can be trusted.
 *
 * This is the single definition. The model calls it, services call it, the
 * database CHECK mirrors it, and the standalone suite tests THIS rather than a
 * reimplementation.
 */
final class AssociationLifecycle
{
    /**
     * Why this state is invalid, or null when it is fine.
     *
     * Returns a reason rather than a boolean so the caller can put a usable
     * message in front of somebody.
     */
    public static function violation(
        ?AssociationManagementStatus $status,
        bool $isApproved,
        bool $hasApprovedAt,
        bool $hasRejectedAt,
        bool $hasRevokedAt,
    ): ?string {
        if ($status === null) {
            return null;   // not yet set; the column default applies
        }

        return match ($status) {
            AssociationManagementStatus::Approved => match (true) {
                ! $isApproved => 'An approved association must have is_approved = true.',
                ! $hasApprovedAt => 'An approved association must record approved_at.',
                default => null,
            },

            AssociationManagementStatus::Rejected => match (true) {
                $isApproved => 'A rejected association must not have is_approved = true.',
                // Symmetry with approved: a rejection with no timestamp cannot
                // be audited, and the database refuses it.
                ! $hasRejectedAt => 'A rejected association must record rejected_at.',
                default => null,
            },

            AssociationManagementStatus::Revoked => match (true) {
                $isApproved => 'A revoked association must not have is_approved = true.',
                ! $hasRevokedAt => 'A revoked association must record revoked_at.',
                default => null,
            },

            AssociationManagementStatus::Pending,
            AssociationManagementStatus::LegacyReview,
            AssociationManagementStatus::Superseded => $isApproved
                ? "An association with status {$status->value} must not have is_approved = true."
                : null,
        };
    }

    /** Convenience for a model instance carrying the standard column names. */
    public static function violationFor(object $model): ?string
    {
        $status = $model->management_status ?? null;

        return self::violation(
            $status instanceof AssociationManagementStatus ? $status : null,
            (bool) ($model->is_approved ?? false),
            ($model->approved_at ?? null) !== null,
            ($model->rejected_at ?? null) !== null,
            ($model->revoked_at ?? null) !== null,
        );
    }
}
