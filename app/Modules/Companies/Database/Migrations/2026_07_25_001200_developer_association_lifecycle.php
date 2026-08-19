<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rejection and revocation columns for developer associations, and the same
 * database-level lifecycle rule the project associations carry (spec 11.2).
 *
 * The new domain shipped with `approved_by`/`approved_at` only, so a rejected
 * or revoked link had nowhere to record who did it or when — the states were
 * expressible and unauditable. It also had no CHECK, so query-builder writes
 * and imports could produce the contradictory rows the project table is
 * protected against.
 */
return new class extends Migration
{
    private const TRIGGER_INSERT = "CREATE TRIGGER IF NOT EXISTS
        company_developer_associations_lifecycle_check_insert
        BEFORE INSERT ON company_developer_associations
        FOR EACH ROW WHEN NOT (
            (NEW.management_status = 'approved' AND NEW.is_approved = 1 AND NEW.approved_at IS NOT NULL)
            OR (NEW.management_status = 'rejected' AND NEW.is_approved = 0 AND NEW.rejected_at IS NOT NULL)
            OR (NEW.management_status = 'revoked' AND NEW.is_approved = 0 AND NEW.revoked_at IS NOT NULL)
            OR (NEW.management_status IN ('pending', 'legacy_review', 'superseded') AND NEW.is_approved = 0)
        )
        BEGIN SELECT RAISE(ABORT, 'developer association lifecycle inconsistent'); END";

    private const TRIGGER_UPDATE = "CREATE TRIGGER IF NOT EXISTS
        company_developer_associations_lifecycle_check_update
        BEFORE UPDATE ON company_developer_associations
        FOR EACH ROW WHEN NOT (
            (NEW.management_status = 'approved' AND NEW.is_approved = 1 AND NEW.approved_at IS NOT NULL)
            OR (NEW.management_status = 'rejected' AND NEW.is_approved = 0 AND NEW.rejected_at IS NOT NULL)
            OR (NEW.management_status = 'revoked' AND NEW.is_approved = 0 AND NEW.revoked_at IS NOT NULL)
            OR (NEW.management_status IN ('pending', 'legacy_review', 'superseded') AND NEW.is_approved = 0)
        )
        BEGIN SELECT RAISE(ABORT, 'developer association lifecycle inconsistent'); END";

    private const CHECK_SQL = "ALTER TABLE company_developer_associations
        ADD CONSTRAINT company_developer_associations_lifecycle_check CHECK (
            (management_status = 'approved' AND is_approved = 1 AND approved_at IS NOT NULL)
            OR (management_status = 'rejected' AND is_approved = 0 AND rejected_at IS NOT NULL)
            OR (management_status = 'revoked' AND is_approved = 0 AND revoked_at IS NOT NULL)
            OR (management_status IN ('pending', 'legacy_review', 'superseded') AND is_approved = 0)
        )";

    private const VIOLATION_SQL = "NOT (
            (management_status = 'approved' AND is_approved = 1 AND approved_at IS NOT NULL)
            OR (management_status = 'rejected' AND is_approved = 0 AND rejected_at IS NOT NULL)
            OR (management_status = 'revoked' AND is_approved = 0 AND revoked_at IS NOT NULL)
            OR (management_status IN ('pending', 'legacy_review', 'superseded') AND is_approved = 0)
        )";

    public function up(): void
    {
        Schema::table('company_developer_associations', function (Blueprint $table): void {
            foreach (['rejected', 'revoked'] as $event) {
                if (! Schema::hasColumn('company_developer_associations', $event.'_by')) {
                    $table->foreignId($event.'_by')->nullable()->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('company_developer_associations', $event.'_at')) {
                    $table->timestamp($event.'_at')->nullable();
                }
            }
        });

        $driver = DB::connection()->getDriverName();

        // Fail closed, exactly as the project-association constraint does.
        $violations = DB::table('company_developer_associations')->whereRaw(self::VIOLATION_SQL)->count();

        if ($violations > 0) {
            throw new RuntimeException(
                'Cannot install the developer association lifecycle constraint: '.$violations.' row(s) violate it.'
            );
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            DB::statement(self::CHECK_SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(self::TRIGGER_INSERT);
            DB::statement(self::TRIGGER_UPDATE);

            return;
        }

        throw new RuntimeException(
            'No lifecycle constraint implementation for driver ['.$driver.']. Refusing to migrate.'
        );
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS company_developer_associations_lifecycle_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS company_developer_associations_lifecycle_check_update');
        } elseif (in_array($driver, ['mysql', 'pgsql'], true)) {
            try {
                DB::statement(
                    'ALTER TABLE company_developer_associations
                     DROP CONSTRAINT company_developer_associations_lifecycle_check'
                );
            } catch (Throwable) {
                // Never applied; nothing to drop.
            }
        }

        Schema::table('company_developer_associations', function (Blueprint $table): void {
            foreach (['rejected', 'revoked'] as $event) {
                if (Schema::hasColumn('company_developer_associations', $event.'_by')) {
                    $table->dropConstrainedForeignId($event.'_by');
                }

                if (Schema::hasColumn('company_developer_associations', $event.'_at')) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropColumn($event.'_at');
                }
            }
        });
    }
};
