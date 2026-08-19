<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead notes, tasks and outcomes (File one §9 Leads 2).
 *
 * `demand_profiles` already carried the stage, the owner and the company;
 * `lead_scores` already carried the score and its reasons. What a sales
 * workspace needs beyond those is the record of what people actually DID —
 * §9 names notes, tasks and outcomes explicitly, and none of the three had
 * anywhere to live.
 *
 * Two decisions here are about protecting the person the lead describes rather
 * than about convenience:
 *
 *   - NOTES ARE ENCRYPTED. A sales note is where somebody writes "wife is
 *     pregnant, wants to move before March" or "father will co-sign". That is
 *     a household's private circumstances recorded by a third party, and a
 *     database dump should not read as a dossier.
 *
 *   - OUTCOMES ARE A CLOSED SET, recorded on the task rather than inferred from
 *     the stage. "Called, no answer" and "called, not interested" move a lead
 *     very differently, and a free-text outcome cannot be reported on or
 *     audited consistently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('demand_profile_id')->constrained('demand_profiles')->cascadeOnDelete();

            // Who wrote it. Retained on user deletion so the audit trail does
            // not develop holes: a note whose author has left is still evidence.
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            // Encrypted: see the class docblock.
            $table->text('body_encrypted');

            /*
             * A note attached to a specific stage transition explains WHY the
             * lead moved, which is the question a manager asks first when
             * reviewing a lost deal.
             */
            $table->string('stage_at_time', 32)->nullable();

            $table->timestamps();

            $table->index(['demand_profile_id', 'created_at']);
        });

        Schema::create('lead_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('demand_profile_id')->constrained('demand_profiles')->cascadeOnDelete();

            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('title');
            $table->string('kind', 32)->default('follow_up');
            $table->date('due_on')->nullable();

            $table->string('status', 24)->default('open')->index();

            /*
             * The closed outcome set. Null until the task is completed —
             * "no outcome yet" and "outcome: none" are different states, and
             * collapsing them would make an untouched task look like a failed
             * one in every report.
             */
            $table->string('outcome', 32)->nullable();
            $table->text('outcome_notes_encrypted')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The two queries a sales workspace actually runs: my open tasks,
            // and everything due on this lead.
            $table->index(['assigned_to_user_id', 'status', 'due_on']);
            $table->index(['demand_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        // MIGRATION-GUARD: intentional-drop — reversing this migration's own
        // tables. Sales notes are a commercial record: back them up before
        // rolling back in an environment where a team has been working.
        Schema::dropIfExists('lead_tasks');
        Schema::dropIfExists('lead_notes');
    }
};
