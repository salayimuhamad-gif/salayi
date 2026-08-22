<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The question-driven valuation rule engine (Wave 6, master spec §20.2
 * "property-specific adjustments").
 *
 * The design constraint everything below serves: a valuation an owner may act
 * on must be reproducible from ITS OWN row forever. So the live rule content
 * (sets, questions, options) and the applied history are two separate worlds —
 * a valuation snapshots the adjustments it applied, label text and percent
 * included, and never joins back to the live tables. Rules can be retired,
 * rewritten as new versions, or deleted outright without a single historical
 * figure changing meaning.
 *
 * Everything here is additive: five new tables and four nullable columns on
 * portfolio_valuations. No existing row is touched, no value is backfilled —
 * a pre-Wave-6 valuation simply has no base_* figures and no adjustment rows,
 * which is the truth about how it was computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valuation_rule_sets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);

            /*
             * Applicability, resolved deterministically at valuation time.
             * scope_transaction states the basis the set adjusts — only
             * 'sale' exists today (the portfolio has no rent flow), but the
             * column keeps the claim explicit instead of implied.
             * project_id null means "any project or none"; property_types
             * null means "every type", otherwise a JSON list of the
             * PortfolioProperty::PROPERTY_TYPES vocabulary.
             */
            $table->string('scope_transaction', 8)->default('sale');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->json('property_types')->nullable();

            /*
             * Versioned lifecycle: draft -> active -> retired. Active and
             * retired content is frozen at the model layer; a correction is
             * a NEW draft version, never an edit, so an owner's persisted
             * answers can never silently change what they mean.
             */
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 12)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Version numbers are unique within a scope family. NULL
            // project_id rows do not collide in a unique index on either
            // engine, so the publisher re-checks global families in code.
            $table->unique(['scope_transaction', 'project_id', 'version']);
            $table->index(['status', 'scope_transaction']);
        });

        Schema::create('valuation_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('valuation_rule_set_id')->constrained('valuation_rule_sets')->cascadeOnDelete();

            /*
             * The stable semantic identity ('renovation_state'), carried into
             * valuation snapshots so history reads across versions even
             * though every version's rows have fresh ids.
             */
            $table->string('key', 64);

            // single_select is the only type Wave 6 ships; the column exists
            // so a future type is a value, not a schema change.
            $table->string('question_type', 24)->default('single_select');

            // Trilingual, all three required: the owner form renders in
            // ckb/ar/en parity and a missing translation would surface as a
            // blank question in someone's language.
            $table->string('label_ckb');
            $table->string('label_ar');
            $table->string('label_en');
            $table->string('help_ckb')->nullable();
            $table->string('help_ar')->nullable();
            $table->string('help_en')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['valuation_rule_set_id', 'key']);
        });

        Schema::create('valuation_question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('valuation_question_id')->constrained('valuation_questions')->cascadeOnDelete();

            $table->string('key', 64);

            $table->string('label_ckb');
            $table->string('label_ar');
            $table->string('label_en');

            /*
             * The AUTHORITATIVE signed percentage, applied server-side only.
             * DECIMAL(6,3) holds the authoring bound (±25.000) with room to
             * spare and no float drift; nothing a client submits is ever
             * read as a percentage.
             */
            $table->decimal('adjustment_percent', 6, 3);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['valuation_question_id', 'key']);
        });

        Schema::create('portfolio_property_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_property_id')->constrained('portfolio_properties')->cascadeOnDelete();
            $table->foreignId('valuation_question_id')->constrained('valuation_questions')->cascadeOnDelete();
            $table->foreignId('valuation_question_option_id')->constrained('valuation_question_options')->cascadeOnDelete();
            $table->timestamps();

            // One answer per question per property — an answer is replaced,
            // never accumulated. IDs only: the option row carries the percent.
            $table->unique(['portfolio_property_id', 'valuation_question_id'], 'ppa_property_question_unique');
        });

        Schema::create('portfolio_valuation_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_valuation_id')->constrained('portfolio_valuations')->cascadeOnDelete();

            /*
             * A SNAPSHOT, not a reference: semantic keys, the trilingual
             * labels as they read at calculation time, and the percent that
             * was actually applied. History must never depend on the live
             * rule tables still existing or still saying the same thing.
             */
            $table->string('question_key', 64);
            $table->string('option_key', 64);
            $table->string('question_ckb');
            $table->string('question_ar');
            $table->string('question_en');
            $table->string('option_ckb');
            $table->string('option_ar');
            $table->string('option_en');
            $table->decimal('adjustment_percent', 6, 3);

            // Display order at calculation time (the question's position),
            // so a breakdown re-renders exactly as it was computed.
            $table->unsignedTinyInteger('position')->default(0);

            $table->unique(['portfolio_valuation_id', 'question_key'], 'pva_valuation_question_unique');
        });

        Schema::table('portfolio_valuations', function (Blueprint $table): void {
            /*
             * The pre-adjustment engine result, kept beside the final
             * figures so a breakdown can show "evidence said X, your answers
             * moved it to Y". Nullable and never backfilled: a row without
             * them predates the rule engine or had no adjustments, and
             * pretending otherwise would rewrite history.
             */
            $table->decimal('base_midpoint', 18, 4)->nullable()->after('high');
            $table->decimal('base_low', 18, 4)->nullable()->after('base_midpoint');
            $table->decimal('base_high', 18, 4)->nullable()->after('base_low');
            $table->decimal('adjustment_total_percent', 8, 3)->nullable()->after('base_high');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_valuations', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additive columns only; no pre-existing column is touched.
            $table->dropColumn(['base_midpoint', 'base_low', 'base_high', 'adjustment_total_percent']);
        });

        // MIGRATION-GUARD: intentional-drop — the five tables this migration
        // created, removed children-first so no foreign key dangles.
        Schema::dropIfExists('portfolio_valuation_adjustments');
        Schema::dropIfExists('portfolio_property_answers');
        Schema::dropIfExists('valuation_question_options');
        Schema::dropIfExists('valuation_questions');
        Schema::dropIfExists('valuation_rule_sets');
    }
};
