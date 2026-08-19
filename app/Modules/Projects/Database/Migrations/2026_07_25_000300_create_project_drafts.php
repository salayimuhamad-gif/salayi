<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumable drafts for the Project Creation Wizard (spec 12.1, 37.2).
 *
 * A project has ~40 fields spread across identity, developer, location,
 * details, pricing and media. Entering them in one form on Erbil mobile data
 * means one dropped connection loses the lot, and the observed result is not
 * that people retype it — it is that they enter less, and the catalogue fills
 * with thin records nobody can act on. The draft is what makes the long form
 * survivable.
 *
 * WHY A SEPARATE TABLE rather than an unpublished `projects` row:
 *
 *   - A half-entered project has no valid `project_type`, `construction_status`
 *     or `delivery_status`, all of which are NOT NULL on `projects`. Making
 *     them nullable to accommodate drafts would weaken the constraint for
 *     every real row.
 *   - Draft state (which step, which steps are done) is wizard bookkeeping,
 *     not project data, and does not belong on a published record.
 *   - Abandoned drafts can be swept without touching the projects table.
 *
 * `payload` is JSON on purpose. Its shape follows the wizard's steps and will
 * change as steps are added; a column per field would mean a migration per
 * wizard revision, and the data is never queried by field — only loaded whole
 * and re-validated on submit. Validation happens on write, so a draft is never
 * trusted just because it was stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so a run that failed partway on a shared host — a query
        // timeout mid-migration is routine there — can simply be repeated.
        if (Schema::hasTable('project_drafts')) {
            return;
        }

        Schema::create('project_drafts', function (Blueprint $table): void {
            $table->id();

            // The draft belongs to the person who started it. Drafts are not
            // shared: two editors filling one form would overwrite each other
            // with no conflict signal.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * Company scoping. A company user's draft is confined to their own
             * company; a platform administrator's is null. This is enforced in
             * the controller too — the column exists so the constraint is
             * visible in the data rather than living only in code.
             */
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            // Set when the wizard is editing an existing project rather than
            // creating one, so a resumed draft cannot silently create a
            // duplicate.
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();

            $table->string('current_step', 32)->default('identity');

            // Step payloads, keyed by step (array<string, mixed> once decoded).
            $table->json('payload');

            // Steps that have passed their own validation (list<string>).
            $table->json('completed_steps');

            // A draft nobody has touched in weeks is abandoned. Recorded rather
            // than inferred from updated_at so a sweep cannot be confused by a
            // bulk column change.
            $table->timestamp('last_touched_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        // MIGRATION-GUARD: intentional-drop — reversing this migration's own
        // table. Drafts are working state; a published project is written to
        // `projects` and is unaffected by dropping this.
        Schema::dropIfExists('project_drafts');
    }
};
