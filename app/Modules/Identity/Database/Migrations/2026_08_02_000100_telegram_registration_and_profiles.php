<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Telegram-Start registration, user profiles, and the Advisor→Leads link.
 *
 * ADDITIVE ONLY, and deliberately engine-neutral: production runs MariaDB (the
 * spatial migrations were hand-patched live from MySQL's `SRID n` to MariaDB's
 * `REF_SYSTEM_ID=n`, which is how we know), the test matrix runs MySQL 8 and
 * SQLite, and nothing below uses syntax that differs between the three.
 *
 * Three concerns share one migration because they ship as one feature and a
 * partial application of them is not a working state anyone wants:
 *
 * 1. `telegram_login_intents` learns to carry a REGISTRATION. The table's
 *    token/session-fingerprint/consume machinery is exactly what registration
 *    needs, so per the reuse rule it is extended rather than duplicated. A
 *    registration intent additionally holds the typed name, the encrypted
 *    phone + blind index (same primitives the users table uses), and the two
 *    consent decisions — everything needed to create the account at the moment
 *    Telegram's webhook proves the Start.
 *
 * 2. `users` gains the Telegram-verification timestamp and the profile fields.
 *    `telegram_verified_at` is the NEW gate for personal features; it is
 *    distinct from `phone_verified` on purpose. Pressing Start proves control
 *    of a Telegram account and nothing about the typed phone number, so the
 *    two claims get two columns and the admin UI can state each honestly.
 *
 * 3. `demand_profiles.advisor_conversation_id`, UNIQUE, so one conversation
 *    can only ever hold one lead row — the idempotency the spec requires is
 *    enforced by the database, not by application code remembering to check.
 *
 * The backfill: every existing user with a `telegram_id` got it through the
 * Share-Contact login flow, which proves MORE than Start does (it proves the
 * phone too). Marking them telegram-verified is therefore recording a fact,
 * not granting anything — and without it, every existing account would be
 * locked out of the Advisor and portfolio the moment the gate switches from
 * `phone_verified` to `telegram_verified_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_login_intents', function (Blueprint $table): void {
            // 'login' keeps every existing row meaning what it always meant.
            $table->string('purpose', 16)->default('login')->index()->after('token');

            $table->string('registration_name', 120)->nullable()->after('locale');
            $table->text('registration_phone_encrypted')->nullable()->after('registration_name');

            /*
             * Indexed, not unique: two people may race the same number through
             * the form, and both intents must be storable — the CONFLICT is
             * decided at redemption time against the users table, where the
             * answer is authoritative. A unique key here would turn a retry
             * into a 500.
             */
            $table->string('registration_phone_index', 64)->nullable()->index()->after('registration_phone_encrypted');

            $table->boolean('consent_terms')->default(false)->after('registration_phone_index');
            $table->boolean('consent_contact')->default(false)->after('consent_terms');
            $table->string('consent_policy_version', 32)->nullable()->after('consent_contact');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('telegram_verified_at')->nullable()->after('telegram_username');
            $table->timestamp('onboarding_completed_at')->nullable()->after('telegram_verified_at');

            $table->string('display_name', 120)->nullable()->after('name');

            // Path + disk pair, so the storage location is data rather than an
            // assumption baked into every reader.
            $table->string('profile_photo_path')->nullable()->after('preferred_locale');
            $table->string('profile_photo_disk', 32)->nullable()->after('profile_photo_path');

            $table->foreignId('profile_area_id')->nullable()->after('profile_photo_disk')
                ->constrained('areas')->nullOnDelete();
            $table->string('profile_bio', 500)->nullable()->after('profile_area_id');
            $table->string('contact_preference', 24)->nullable()->after('profile_bio');
            $table->string('primary_purpose', 24)->nullable()->after('contact_preference');
        });

        Schema::table('demand_profiles', function (Blueprint $table): void {
            /*
             * nullOnDelete, not cascade: a deleted conversation must not take
             * the LEAD with it — the lead is the business record, the
             * conversation is its provenance.
             */
            $table->foreignId('advisor_conversation_id')->nullable()->after('lifestyle_profile_id')
                ->constrained('advisor_conversations')->nullOnDelete();

            $table->unique('advisor_conversation_id', 'demand_profiles_advisor_conversation_unique');
        });

        /*
         * Backfill, guarded to rows that are unambiguously already linked.
         * COALESCE to the most truthful timestamp available: the last login is
         * when the link was last exercised; created_at is when it began.
         */
        DB::table('users')
            ->whereNotNull('telegram_id')
            ->whereNull('telegram_verified_at')
            ->update(['telegram_verified_at' => DB::raw('COALESCE(last_login_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('demand_profiles', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additions only; no pre-existing column or data is touched.
            //
            // Order matters and MySQL enforces it: the foreign key USES the
            // unique index, so dropping the index first fails with error 1553.
            // Foreign key, then index, then column.
            $table->dropForeign(['advisor_conversation_id']);
            $table->dropUnique('demand_profiles_advisor_conversation_unique');
            $table->dropColumn('advisor_conversation_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additions only.
            $table->dropConstrainedForeignId('profile_area_id');
            $table->dropColumn([
                'telegram_verified_at',
                'onboarding_completed_at',
                'display_name',
                'profile_photo_path',
                'profile_photo_disk',
                'profile_bio',
                'contact_preference',
                'primary_purpose',
            ]);
        });

        Schema::table('telegram_login_intents', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additions only.
            //
            // The index goes FIRST, explicitly. MySQL would drop it with the
            // column; SQLite refuses to drop a column an index still covers,
            // and the parity gate runs there — the same reversal must be
            // clean on both engines.
            $table->dropIndex('telegram_login_intents_purpose_index');
            $table->dropIndex('telegram_login_intents_registration_phone_index_index');
            $table->dropColumn([
                'purpose',
                'registration_name',
                'registration_phone_encrypted',
                'registration_phone_index',
                'consent_terms',
                'consent_contact',
                'consent_policy_version',
            ]);
        });
    }
};
