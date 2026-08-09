<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two optional profile details: gender and date of birth.
 *
 * Both are NULLABLE and neither gates anything. No existing business rule
 * needs them, so nothing here is allowed to start requiring them — a column
 * existing is not a reason to demand it be filled in.
 *
 * DATE OF BIRTH, NOT AGE. An age is a number that silently becomes wrong every
 * year, and a row updated by nobody drifts from the truth on its own. The date
 * is a fact that stays a fact; anything that wants an age derives one.
 *
 * GENDER is a short free-list validated in the controller rather than an
 * enum column, because the acceptable values are a product decision that will
 * change more often than the schema should. `char(16)` is wide enough for the
 * values in use and narrow enough to stay an index-friendly column if one is
 * ever needed.
 *
 * CITY AND AREA ARE DELIBERATELY ABSENT. `users.profile_area_id` already exists
 * and already points at the admin-managed `areas` table, whose hierarchy runs
 * city → district → nahiya → sector → zone → neighborhood. Storing a city
 * alongside it would create a second source of truth that can disagree with the
 * first; the city is DERIVED from the selected area's materialised path
 * instead. That also means the moment an administrator publishes real Erbil
 * neighbourhoods, the picker offers them with no migration and no code change —
 * which is exactly the requirement, and why nothing is hard-coded here.
 *
 * Forward-only and additive: no existing column or row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('gender', 16)->nullable()->after('profile_bio');
            $table->date('date_of_birth')->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additions only; no pre-existing column or data is touched.
            $table->dropColumn(['gender', 'date_of_birth']);
        });
    }
};
