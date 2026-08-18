<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demand profiles, signals, scoring and alerts (spec 22.3, 23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('lifestyle_profile_id')->nullable()->constrained('lifestyle_profiles')->nullOnDelete();
            $table->string('preferred_locale', 8)->default('ckb');

            $table->decimal('budget_min', 18, 4)->nullable();
            $table->decimal('budget_max', 18, 4)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('financing', 24)->nullable();
            $table->string('objective', 24)->nullable();
            $table->string('property_type', 32)->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->decimal('size_min_sqm', 10, 2)->nullable();
            $table->decimal('size_max_sqm', 10, 2)->nullable();
            $table->string('timeframe', 24)->nullable();
            $table->string('risk_preference', 16)->nullable();
            $table->string('return_preference', 16)->nullable();

            // AI summary is stored separately from the structured fields it
            // describes, and never replaces them (spec 17.5).
            $table->text('ai_summary')->nullable();
            $table->string('ai_summary_locale', 8)->nullable();

            $table->string('stage', 24)->default('new')->index();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('source', 48)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'stage']);
        });

        Schema::create('lead_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('demand_profile_id')->constrained('demand_profiles')->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->string('subject_type', 48)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->string('source', 48)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['demand_profile_id', 'type', 'subject_type', 'subject_id'], 'lead_signals_unique_subject');
        });

        Schema::create('lead_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('demand_profile_id')->constrained('demand_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('total');
            $table->unsignedInteger('uncapped_total')->default(0);
            $table->string('rule_version', 16);
            // Spec 23.3 requires each reason and its points to be stored.
            $table->json('reasons');
            $table->unsignedTinyInteger('calculated_total')->nullable();
            $table->boolean('is_overridden')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at')->useCurrent();

            $table->index(['demand_profile_id', 'calculated_at']);
        });

        Schema::create('alert_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 32)->index();
            $table->string('subject_type', 48)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('saved_search_id')->nullable()->constrained('saved_searches')->cascadeOnDelete();
            $table->json('channels');
            $table->string('frequency', 16)->default('immediate');
            $table->boolean('is_active')->default(true);
            // Spec 22.3: every alert carries an unsubscribe. The token lives on
            // the subscription so a one-click unsubscribe needs no session.
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'is_active']);
        });

        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_subscription_id')->constrained('alert_subscriptions')->cascadeOnDelete();
            $table->string('channel', 16);
            // Spec 22.3: every alert states why it was sent and what matched.
            // NOT NULL, so an unexplained alert cannot be recorded as sent.
            $table->string('why');
            $table->string('matched_rule', 96);
            $table->string('locale', 8)->default('ckb');
            $table->string('status', 24)->default('queued')->index();
            $table->string('failure_reason', 96)->nullable();
            // Proof the consent gate was consulted before sending, not after.
            $table->string('consent_checked', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['alert_subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alert_subscriptions');
        Schema::dropIfExists('lead_scores');
        Schema::dropIfExists('lead_signals');
        Schema::dropIfExists('demand_profiles');
    }
};
