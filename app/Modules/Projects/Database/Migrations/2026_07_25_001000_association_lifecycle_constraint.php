<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level lifecycle consistency for associations (spec 11.2).
 *
 * The model's `saving` guard protects Eloquent writes and nothing else.
 * Query-builder updates, CSV imports, the installer's seeders and a DBA
 * fixing something by hand all bypass it entirely — and an inconsistent row
 * is invisible rather than loud: ProjectScope requires status and
 * `is_approved` to agree, so a contradictory row silently confers nothing
 * while every admin listing shows it as approved.
 *
 * The constraint states the same rules the model does, one layer down where
 * nothing can route around them.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'company_project_associations_lifecycle_check';

    /*
     * The lifecycle rule, written out LITERALLY rather than built by
     * concatenation.
     *
     * The security audit refuses interpolated raw SQL, and it is right to: a
     * migration that assembles DDL from strings is one refactor away from
     * assembling it from input. The cost is that the rule appears three times
     * — once for the CHECK and once per trigger prefix — so the constants sit
     * adjacent and any edit to one is obviously an edit to all three.
     *
     * THE CONSTRAINT NAME IS PART OF THAT LITERAL. It previously read
     * `ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (` — concatenation syntax
     * pasted inside a double-quoted string, where PHP does not concatenate.
     * MySQL therefore received the characters `'.self::CONSTRAINT.'` where an
     * identifier belongs and refused the statement with error 1064, so EVERY
     * fresh MySQL install died at this migration.
     *
     * SQLite never reached the line — it takes the trigger branch below — which
     * is precisely why a suite green on SQLite shipped a release that could not
     * migrate on the engine it runs on in production.
     *
     * The name is now spelled out, matching the sibling migration
     * 2026_07_25_001200_developer_association_lifecycle.php, which had it right
     * all along. self::CONSTRAINT remains the single source of truth for down()
     * and for anything that needs to name the constraint in PHP; the two must
     * stay in step, so they are adjacent.
     */
    private const CHECK_SQL = "ALTER TABLE company_project_associations
        ADD CONSTRAINT company_project_associations_lifecycle_check CHECK (
            (management_status = 'approved' AND is_approved = 1 AND approved_at IS NOT NULL)
            OR (management_status = 'pending' AND is_approved = 0)
            OR (management_status = 'rejected' AND is_approved = 0 AND rejected_at IS NOT NULL)
            OR (management_status = 'revoked' AND is_approved = 0 AND revoked_at IS NOT NULL)
            OR (management_status IN ('legacy_review', 'superseded') AND is_approved = 0)
        )";

    private const TRIGGER_INSERT_SQL = "CREATE TRIGGER IF NOT EXISTS
        company_project_associations_lifecycle_check_insert
        BEFORE INSERT ON company_project_associations
        FOR EACH ROW WHEN NOT (
            (NEW.management_status = 'approved' AND NEW.is_approved = 1 AND NEW.approved_at IS NOT NULL)
            OR (NEW.management_status = 'pending' AND NEW.is_approved = 0)
            OR (NEW.management_status = 'rejected' AND NEW.is_approved = 0 AND NEW.rejected_at IS NOT NULL)
            OR (NEW.management_status = 'revoked' AND NEW.is_approved = 0 AND NEW.revoked_at IS NOT NULL)
            OR (NEW.management_status IN ('legacy_review', 'superseded') AND NEW.is_approved = 0)
        )
        BEGIN SELECT RAISE(ABORT, 'association lifecycle inconsistent'); END";

    private const TRIGGER_UPDATE_SQL = "CREATE TRIGGER IF NOT EXISTS
        company_project_associations_lifecycle_check_update
        BEFORE UPDATE ON company_project_associations
        FOR EACH ROW WHEN NOT (
            (NEW.management_status = 'approved' AND NEW.is_approved = 1 AND NEW.approved_at IS NOT NULL)
            OR (NEW.management_status = 'pending' AND NEW.is_approved = 0)
            OR (NEW.management_status = 'rejected' AND NEW.is_approved = 0 AND NEW.rejected_at IS NOT NULL)
            OR (NEW.management_status = 'revoked' AND NEW.is_approved = 0 AND NEW.revoked_at IS NOT NULL)
            OR (NEW.management_status IN ('legacy_review', 'superseded') AND NEW.is_approved = 0)
        )
        BEGIN SELECT RAISE(ABORT, 'association lifecycle inconsistent'); END";

    /** The same predicate, for finding rows that already violate it. */
    private const VIOLATION_SQL = "NOT (
            (management_status = 'approved' AND is_approved = 1 AND approved_at IS NOT NULL)
            OR (management_status = 'pending' AND is_approved = 0)
            OR (management_status = 'rejected' AND is_approved = 0 AND rejected_at IS NOT NULL)
            OR (management_status = 'revoked' AND is_approved = 0 AND revoked_at IS NOT NULL)
            OR (management_status IN ('legacy_review', 'superseded') AND is_approved = 0)
        )";

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        /*
         * FAIL CLOSED.
         *
         * The previous version caught every exception, logged a warning and
         * continued — while its own comment said a violation "must be reported
         * and fixed, not silently tolerated". A migration that reports success
         * having installed no constraint is worse than one that fails, because
         * the operator then believes the rule is in force.
         */
        $violations = DB::table('company_project_associations')
            ->whereRaw(self::VIOLATION_SQL)
            ->count();

        if ($violations > 0) {
            $examples = DB::table('company_project_associations')
                ->whereRaw(self::VIOLATION_SQL)
                ->limit(5)
                ->pluck('id')
                ->implode(', ');

            throw new RuntimeException(
                'Cannot install the association lifecycle constraint: '.$violations
                .' row(s) violate it. Example ids: '.$examples
                .'. Reconcile management_status with is_approved and the corresponding timestamp.'
            );
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            DB::statement(self::CHECK_SQL);

            return;
        }

        if ($driver === 'sqlite') {
            /*
             * SQLite cannot ALTER TABLE ADD CONSTRAINT, so the SAME rule is
             * enforced by triggers. Identical semantics matter more than
             * identical mechanism: a suite running on SQLite that permits what
             * MySQL rejects is testing a different application than the one
             * that ships.
             */
            DB::statement(self::TRIGGER_INSERT_SQL);
            DB::statement(self::TRIGGER_UPDATE_SQL);

            return;
        }

        throw new RuntimeException(
            'The association lifecycle constraint has no implementation for driver ['.$driver
            .']. Refusing to migrate rather than leave the rule unenforced.'
        );
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS company_project_associations_lifecycle_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS company_project_associations_lifecycle_check_update');

            return;
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            try {
                DB::statement(
                    'ALTER TABLE company_project_associations
                     DROP CONSTRAINT '.self::CONSTRAINT
                );
            } catch (Throwable) {
                // Tolerated only on the way DOWN, where a missing constraint
                // is the desired end state anyway.
            }
        }
    }
};
