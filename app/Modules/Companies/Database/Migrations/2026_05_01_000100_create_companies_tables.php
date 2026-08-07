<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companies, branches and project associations (spec 18.1-18.3).
 *
 * Two structural commitments:
 *
 *   - A company is unpublished until an administrator approves it (spec 37.4
 *     "company requires approval"). `verification_status` defaults to pending
 *     and the published scope requires 'verified'.
 *   - An association carries its own approval, dates and disclosure label, so
 *     "official sales partner" on a project page is always an administrator's
 *     assertion with an audit trail behind it, never a company's self-claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 191)->unique();
            $table->string('external_id', 64)->nullable()->unique();

            $table->string('legal_name');
            $table->string('brand_name')->nullable();
            $table->string('name_ckb')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('search_key', 191)->nullable()->index();

            $table->text('description_ckb')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->json('specialties')->nullable();
            $table->json('languages')->nullable();
            $table->json('operating_area_ids')->nullable();

            $table->string('logo_path')->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            // Company contact numbers are business data, but a sole trader's
            // number is also personal data. Encrypted for the same reason a
            // place phone is (spec 32.2).
            $table->text('phones_encrypted')->nullable();
            $table->text('whatsapp_encrypted')->nullable();
            $table->string('telegram_username', 64)->nullable();
            $table->json('social_links')->nullable();

            $table->string('license_number', 64)->nullable();
            $table->string('license_authority')->nullable();
            $table->date('license_expires_at')->nullable();
            $table->json('license_evidence')->nullable();

            // Spec 37.4: company requires approval.
            $table->string('verification_status', 24)->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable();

            $table->string('subscription_plan', 32)->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            $table->boolean('advertising_enabled')->default(false);
            $table->unsignedInteger('median_response_minutes')->nullable();

            $table->string('publication_status', 24)->default('draft')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('company_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();

            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('address_ckb')->nullable();
            $table->string('address_ar')->nullable();
            $table->string('address_en')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Spec 37.4: "branch has unique contact data." A branch that
            // silently inherits head-office contact details makes the branch
            // listing useless to a caller.
            $table->text('phone_encrypted')->nullable();
            $table->text('whatsapp_encrypted')->nullable();
            $table->string('email')->nullable();
            $table->json('opening_hours')->nullable();
            $table->json('languages')->nullable();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('company_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_branch_id')->nullable()->constrained('company_branches')->nullOnDelete();
            $table->string('role', 32)->default('company_staff');
            $table->string('title')->nullable();
            // Portal permissions are per-membership, not per-user: the same
            // person may be an owner at one company and staff at another.
            $table->boolean('may_manage_offers')->default(false);
            $table->boolean('may_view_leads')->default(false);
            $table->boolean('may_view_lead_contacts')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('company_project_associations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('company_branch_id')->nullable()->constrained('company_branches')->nullOnDelete();

            $table->string('role', 40)->index();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Spec 37.4: admin-controlled. Never self-granted.
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedSmallInteger('display_priority')->default(0);
            $table->boolean('is_sponsored')->default(false);
            // Spec 18.3 lists a disclosure label as part of the association.
            $table->string('disclosure_label')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'project_id', 'role']);
            /*
             * NAMED EXPLICITLY, because the generated name is too long for MySQL.
             *
             * Laravel would derive
             * `company_project_associations_project_id_is_approved_display_priority_index`
             * — 74 characters against MySQL's 64-character identifier limit — so
             * `migrate:fresh` aborted with SQLSTATE[42000] 1059 and the platform
             * could not be installed on its own documented production engine at
             * all. SQLite has no such limit, which is why every green run hid it.
             *
             * This migration is edited in place rather than repaired forward:
             * it has never executed successfully against any MySQL database, so
             * there is no deployed state to preserve, and a forward migration
             * cannot rename an index that was never created.
             */
            $table->index(
                ['project_id', 'is_approved', 'display_priority'],
                'cpa_project_approved_priority_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_project_associations');
        Schema::dropIfExists('company_staff');
        Schema::dropIfExists('company_branches');
        Schema::dropIfExists('companies');
    }
};
