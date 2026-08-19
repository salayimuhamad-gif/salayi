<?php

declare(strict_types=1);

use App\Modules\Companies\Enums\AssociationManagementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Association provenance, and a SAFE backfill (spec 11.2).
 *
 * The previous migration added `management_status` with a default of
 * `pending`. On an upgraded database that silently promoted every existing
 * unapproved association to a management-granting state — rows nobody
 * reviewed, whose creator is unknown, some of them years old. Running a
 * migration must never widen authorisation.
 *
 * THE BACKFILL RULE
 *
 *   is_approved = true   -> approved      (it was reviewed and accepted)
 *   is_approved = false  -> legacy_review (no provenance, grants nothing)
 *   expired (ends_on past) -> superseded  (the relationship already ended)
 *
 * `legacy_review` is deliberately not `rejected`: these rows have not been
 * refused, only never evidenced. They need a human, and until then they confer
 * nothing.
 *
 * PROVENANCE FIELDS make pending safe. A pending association grants access
 * only when it can point at the ProjectDraft that created it — which is
 * something only the Wizard can produce, and only for the company that draft
 * was scoped to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_project_associations', function (Blueprint $table): void {
            if (! Schema::hasColumn('company_project_associations', 'created_via_project_draft_id')) {
                /*
                 * The draft this association came from. Nullable because
                 * platform-created and legacy associations have none — and
                 * their absence is exactly what makes a pending row untrusted.
                 *
                 * nullOnDelete: pruning an old draft must not delete a live
                 * association, but the provenance link legitimately expires
                 * with it, at which point the row must already be approved.
                 */
                /*
                 * The constraint is NAMED, because the generated name is one
                 * character too long for MySQL.
                 *
                 * Laravel derives
                 * `company_project_associations_created_via_project_draft_id_foreign`
                 * — 65 characters against MySQL's 64-character identifier limit
                 * — so `migrate:fresh` aborted with SQLSTATE[42000] 1059 and the
                 * platform could not be installed on its documented production
                 * engine. SQLite imposes no such limit, which is why the whole
                 * suite stayed green while this was broken.
                 *
                 * Edited in place rather than repaired forward: it has never
                 * executed successfully against MySQL, so there is no deployed
                 * state to preserve, and a forward migration cannot rename a
                 * constraint that was never created.
                 */
                $table->foreignId('created_via_project_draft_id')->nullable();

                $table->foreign('created_via_project_draft_id', 'cpa_created_via_draft_foreign')
                    ->references('id')->on('project_drafts')->nullOnDelete();
            }

            /*
             * `approved_by` and `approved_at` are NOT listed: the base
             * companies migration already creates them. The hasColumn guard
             * meant up() skipped them harmlessly, but down() would have
             * DROPPED them — destroying who approved every association on the
             * platform, including rows that predate the wizard entirely. A
             * one-step rollback must never remove somebody else's columns.
             */
            foreach (['rejected', 'revoked'] as $event) {
                if (! Schema::hasColumn('company_project_associations', $event.'_by')) {
                    $table->foreignId($event.'_by')->nullable()->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('company_project_associations', $event.'_at')) {
                    $table->timestamp($event.'_at')->nullable();
                }
            }
        });

        $this->backfill();

        /*
         * A CHECK constraint where the engine supports it. MySQL 8 and modern
         * SQLite both do; the guard keeps the migration portable rather than
         * assuming. An enum cast protects application writes, but a constraint
         * also protects the imports and manual fixes that bypass Eloquent.
         */
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'sqlite', 'pgsql'], true)) {
            $values = implode(
                ', ',
                array_map(static fn (string $v): string => "'".$v."'", AssociationManagementStatus::values()),
            );

            try {
                DB::statement(
                    'ALTER TABLE company_project_associations
                     ADD CONSTRAINT company_project_associations_management_status_check
                     CHECK (management_status IN ('.$values.'))'
                );
            } catch (Throwable $e) {
                // SQLite cannot add a constraint to an existing table. The
                // enum cast still guards every application write; this is
                // defence in depth, not the only defence.
                Log::info('Management-status check constraint not applied', [
                    'driver' => $driver,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Assign a status to every existing row from evidence, never by default.
     */
    private function backfill(): void
    {
        $now = now();

        // Reviewed and accepted: the only rows with real evidence of approval.
        DB::table('company_project_associations')
            ->where('is_approved', true)
            ->update([
                'management_status' => AssociationManagementStatus::Approved->value,
                'approved_at' => DB::raw('COALESCE(approved_at, updated_at)'),
                'status_changed_at' => $now,
            ]);

        // Everything else: no provenance, so no authority.
        DB::table('company_project_associations')
            ->where('is_approved', false)
            ->whereNull('created_via_project_draft_id')
            ->update([
                'management_status' => AssociationManagementStatus::LegacyReview->value,
                'status_changed_at' => $now,
            ]);

        /*
         * Already over, whatever it once was.
         *
         * `is_approved` MUST be cleared with the status. Setting an expired
         * approved row to `superseded` while leaving is_approved = true
         * produces exactly the contradiction the lifecycle constraint
         * forbids — so migration 001000 would then fail on any real database
         * containing an expired approved association, which is most of them.
         * The two columns describe one fact and have to move together.
         */
        DB::table('company_project_associations')
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', $now->toDateString())
            ->update([
                'management_status' => AssociationManagementStatus::Superseded->value,
                'is_approved' => false,
                'status_changed_at' => $now,
            ]);

        /*
         * Approved rows must carry approved_at, which the constraint also
         * requires. A legacy row approved before the column existed has none;
         * updated_at is the closest honest answer and is already used above.
         */
        DB::table('company_project_associations')
            ->where('management_status', AssociationManagementStatus::Approved->value)
            ->whereNull('approved_at')
            ->update(['approved_at' => DB::raw('updated_at')]);

        // Inconsistent rows are REPORTED rather than guessed at.
        $inconsistent = DB::table('company_project_associations')
            ->where('is_approved', true)
            ->where('management_status', '!=', AssociationManagementStatus::Approved->value)
            ->count();

        if ($inconsistent > 0) {
            Log::warning('Associations with inconsistent approval state need review', [
                'count' => $inconsistent,
            ]);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            try {
                DB::statement(
                    'ALTER TABLE company_project_associations
                     DROP CONSTRAINT company_project_associations_management_status_check'
                );
            } catch (Throwable) {
                // Never applied; nothing to drop.
            }
        }

        Schema::table('company_project_associations', function (Blueprint $table): void {
            if (Schema::hasColumn('company_project_associations', 'created_via_project_draft_id')) {
                /*
                 * DROPPED BY ITS EXPLICIT NAME.
                 *
                 * `dropConstrainedForeignId()` derives the conventional name,
                 * which is precisely the 65-character identifier MySQL refused
                 * to create — so the rollback tried to drop a constraint that
                 * had never existed under that name and aborted the whole
                 * batch. The name given in up() is the name used here.
                 */
                /*
                 * PER ENGINE, because the two differ in what they can express.
                 *
                 * MySQL needs the constraint dropped BY THE EXPLICIT NAME given
                 * in up(); `dropConstrainedForeignId()` would derive the
                 * conventional 65-character name MySQL refused to create, and
                 * the rollback would abort trying to drop something that never
                 * existed.
                 *
                 * SQLite cannot drop a foreign key at all, and refuses to drop
                 * a column its table definition still references — so it needs
                 * Laravel's combined helper, which rebuilds the table and drops
                 * the constraint with it.
                 */
                if (DB::connection()->getDriverName() === 'sqlite') {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropConstrainedForeignId('created_via_project_draft_id');
                } else {
                    $table->dropForeign('cpa_created_via_draft_foreign');

                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropColumn('created_via_project_draft_id');
                }
            }

            // ONLY the columns this migration introduced. approved_by and
            // approved_at belong to the base companies migration and survive.
            foreach (['rejected', 'revoked'] as $event) {
                if (Schema::hasColumn('company_project_associations', $event.'_by')) {
                    $table->dropConstrainedForeignId($event.'_by');
                }

                if (Schema::hasColumn('company_project_associations', $event.'_at')) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropColumn($event.'_at');
                }
            }
        });
    }
};
