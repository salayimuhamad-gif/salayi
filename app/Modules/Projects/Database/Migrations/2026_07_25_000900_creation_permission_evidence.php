<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable evidence that a creator held project rights AT CREATION TIME.
 *
 * Checking the CURRENT `company_staff` row proves what is true now, not what
 * was true when the association was written. Two failures follow from that,
 * in opposite directions:
 *
 *   - a user granted `may_manage_projects` LATER retroactively validates a
 *     pending association they created without it;
 *   - and there is no record at all of the membership that authorised it, so a
 *     dispute cannot be settled from the data.
 *
 * These columns are written once, at submission, and never updated. Current
 * membership remains a separate, additional requirement — losing project
 * rights removes pending access going forward, which is a policy decision
 * about the present, not a rewriting of the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_project_associations', function (Blueprint $table): void {
            if (! Schema::hasColumn('company_project_associations', 'created_by_company_staff_id')) {
                /*
                 * The exact membership row that authorised this.
                 *
                 * NO foreign key, deliberately. `nullOnDelete` would erase the
                 * only exact evidence the moment somebody removed a staff
                 * record — which is precisely when the history matters most,
                 * and it would silently revoke access to every association
                 * that person created. `restrict` is no better: it would make
                 * a departing employee undeletable.
                 *
                 * The id is stored as a plain reference and the SNAPSHOT
                 * columns below carry the facts, so the evidence survives the
                 * row it points at.
                 */
                $table->unsignedBigInteger('created_by_company_staff_id')->nullable()->index();
            }

            if (! Schema::hasColumn('company_project_associations', 'creator_membership_role')) {
                // Snapshot, not a lookup: the role AS IT WAS when the
                // association was created.
                $table->string('creator_membership_role', 32)->nullable();
            }

            if (! Schema::hasColumn('company_project_associations', 'creator_membership_company_id')) {
                // The company the membership belonged to, captured so the
                // evidence remains self-contained if the staff row is gone.
                $table->unsignedBigInteger('creator_membership_company_id')->nullable();
            }

            if (! Schema::hasColumn('company_project_associations', 'creator_manage_projects_confirmed_at')) {
                /*
                 * The moment the right was verified. Present means "we checked
                 * and it held"; null means no evidence, which ProjectScope
                 * treats as no authority.
                 */
                $table->timestamp('creator_manage_projects_confirmed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        MigrationIndexes::dropIndexesOn('company_project_associations', [
            'created_by_company_staff_id',
            'creator_membership_role',
            'creator_membership_company_id',
            'creator_manage_projects_confirmed_at',
        ]);

        Schema::table('company_project_associations', function (Blueprint $table): void {
            foreach ([
                'created_by_company_staff_id',
                'creator_membership_role',
                'creator_membership_company_id',
                'creator_manage_projects_confirmed_at',
            ] as $column) {
                if (Schema::hasColumn('company_project_associations', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropColumn($column);
                }
            }
        });
    }
};
