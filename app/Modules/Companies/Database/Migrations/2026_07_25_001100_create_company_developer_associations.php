<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which developers a company may attribute projects to (spec 11.2).
 *
 * THE DEADLOCK THIS RESOLVES.
 *
 * Permitted developers were derived from the company's existing manageable
 * projects. A company entering its FIRST project therefore had no permitted
 * developer at all — the very moment the field matters most. The rule was
 * circular: you may name a developer once you already have a project naming
 * that developer.
 *
 * The relationship is now stated directly rather than inferred. A company is
 * linked to the developers it actually represents, with the same lifecycle
 * discipline as project associations: a status, provenance, and validity
 * dates, so a link can be pending review, approved, or withdrawn without
 * being deleted.
 *
 * Existing links are backfilled from real project associations, so a company
 * that already has projects keeps exactly the developers it could name before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_developer_associations')) {
            return;
        }

        Schema::create('company_developer_associations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('developer_id')->constrained('developers')->cascadeOnDelete();

            /*
             * Same vocabulary as AssociationManagementStatus, deliberately:
             * one lifecycle language across the product means an operator who
             * understands one screen understands the other.
             *
             * Defaults to `pending` — a claimed relationship confers nothing
             * until reviewed, and a company cannot grant itself the right to
             * attribute projects to a developer it does not represent.
             */
            $table->string('management_status', 16)->default('pending')->index();

            $table->boolean('is_approved')->default(false);

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // One live link per pair. A company either represents a developer
            // or does not; two rows saying different things is unanswerable.
            $table->unique(['company_id', 'developer_id']);
        });

        $this->backfillFromProjects();
    }

    /**
     * Grant every company the developers it can already legitimately name.
     *
     * Without this the migration would REMOVE capability: a company with
     * twenty projects would suddenly have no permitted developers. Backfilled
     * rows are marked approved because the underlying project association was
     * already approved — the evidence exists, it was simply implicit.
     */
    private function backfillFromProjects(): void
    {
        $now = now();

        $pairs = DB::table('company_project_associations as cpa')
            ->join('projects as p', 'p.id', '=', 'cpa.project_id')
            ->whereNotNull('p.developer_id')
            ->where('cpa.management_status', 'approved')
            ->where('cpa.is_approved', true)
            ->select('cpa.company_id', 'p.developer_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            DB::table('company_developer_associations')->insertOrIgnore([
                'company_id' => $pair->company_id,
                'developer_id' => $pair->developer_id,
                'management_status' => 'approved',
                'is_approved' => true,
                'approved_at' => $now,
                'status_changed_at' => $now,
                'notes' => 'Backfilled from an existing approved project association.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // MIGRATION-GUARD: intentional-drop — reversing this migration's own
        // table. Project associations and developers are untouched; reverting
        // restores the previous (circular) derivation.
        Schema::dropIfExists('company_developer_associations');
    }
};
