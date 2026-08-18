<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advisor schema (spec 16, 17).
 *
 * The design commitment: an AI answer cannot exist in this database without its
 * evidence and its validation result. Spec 17.4 lists ten fields every answer
 * must store, and they are columns rather than a JSON blob so a missing one is
 * a schema error rather than a quietly absent key behind a published answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifestyle_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Anonymous sessions get a profile too, so a visitor can use the
            // advisor before registering (spec 4.1 discovery journey).
            $table->string('session_key', 64)->nullable()->index();
            $table->string('label')->nullable();

            $table->decimal('budget_min', 18, 4)->nullable();
            $table->decimal('budget_max', 18, 4)->nullable();
            $table->string('budget_currency', 3)->default('USD');
            $table->json('property_types')->nullable();
            $table->json('lifestyle_factors')->nullable();
            $table->unsignedTinyInteger('household_adults')->nullable();
            $table->unsignedTinyInteger('household_children')->nullable();

            $table->string('locale', 8)->default('ckb');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
        });

        Schema::create('lifestyle_priorities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lifestyle_profile_id')->constrained('lifestyle_profiles')->cascadeOnDelete();
            // Spec 16.1: fourteen priority kinds including custom_location.
            $table->string('kind', 32);
            $table->string('label')->nullable();

            // A user's workplace and their children's schools are among the
            // most sensitive locations this product holds (spec 32.2).
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();

            $table->unsignedTinyInteger('importance')->default(3);
            $table->unsignedInteger('max_distance_m')->nullable();
            $table->unsignedInteger('max_travel_time_s')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('travel_mode', 16)->default('driving');
            $table->timestamps();

            $table->index(['lifestyle_profile_id', 'kind']);
        });

        Schema::create('advisor_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('session_key', 64)->nullable()->index();
            $table->foreignId('lifestyle_profile_id')->nullable()->constrained('lifestyle_profiles')->nullOnDelete();

            // Spec 17.2 agent identity, so an evaluation can be scoped to one
            // agent rather than to "the AI" generally.
            $table->string('agent', 48)->index();
            $table->string('locale', 8)->default('ckb');
            $table->string('status', 24)->default('open')->index();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('advisor_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advisor_conversation_id')->constrained('advisor_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->string('locale', 8)->default('ckb');

            // Spec 17.5: "Always distinguish fact, analysis, scenario, and
            // advertisement." Stored per message so the interface can label it
            // rather than relying on the prose to say so.
            $table->string('content_class', 24)->default('analysis');

            // --- Spec 17.4: every AI answer stores all of these ---
            $table->json('evidence_ids')->nullable();
            $table->json('evidence_types')->nullable();
            $table->json('evidence_dates')->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('provider', 32)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('validation_result', 24)->nullable()->index();
            $table->json('validation_detail')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();

            // A message that failed numeric grounding is retained for review
            // but never rendered. Deleting it would erase the evidence that
            // the guard worked.
            $table->boolean('is_withheld')->default(false);
            $table->string('withheld_reason', 64)->nullable();

            $table->timestamps();

            $table->index(['advisor_conversation_id', 'created_at']);
        });

        Schema::create('advisor_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lifestyle_profile_id')->constrained('lifestyle_profiles')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('advisor_conversation_id')->nullable()->constrained('advisor_conversations')->nullOnDelete();

            $table->unsignedTinyInteger('score');
            // Spec 16.3: the breakdown is stored alongside the score, because a
            // score without its components cannot be explained to a buyer who
            // asks why one project outranked another.
            $table->json('components');
            $table->boolean('is_disqualified')->default(false);
            $table->json('disqualification_reasons')->nullable();
            $table->string('confidence', 16)->default('low');

            // The narrative is stored separately from the score it describes,
            // and the score is authoritative. Spec 16.3: AI explains the
            // calculated result, it does not decide it.
            $table->longText('narrative')->nullable();
            $table->string('narrative_locale', 8)->nullable();
            $table->foreignId('narrative_message_id')->nullable()->constrained('advisor_messages')->nullOnDelete();

            $table->string('matcher_version', 16)->default('v1');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['lifestyle_profile_id', 'project_id']);
            $table->index(['lifestyle_profile_id', 'score']);
        });

        Schema::create('advisor_prompts', function (Blueprint $table): void {
            $table->id();
            $table->string('agent', 48);
            $table->string('locale', 8);
            $table->string('version', 32);
            $table->longText('system_prompt');
            $table->json('allowed_tools')->nullable();
            $table->decimal('temperature', 3, 2)->default(0.20);
            $table->unsignedInteger('max_tokens')->default(1200);
            $table->boolean('is_active')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['agent', 'locale', 'version']);
            $table->index(['agent', 'locale', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_prompts');
        Schema::dropIfExists('advisor_matches');
        Schema::dropIfExists('advisor_messages');
        Schema::dropIfExists('advisor_conversations');
        Schema::dropIfExists('lifestyle_priorities');
        Schema::dropIfExists('lifestyle_profiles');
    }
};
