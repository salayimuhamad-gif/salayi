<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presence, at the honesty level the infrastructure supports.
 *
 * `last_seen_at` is written by TouchLastSeen at most once per interval per
 * user — a cache gate absorbs every other request — so "online" means
 * "made a request recently", never a realtime promise. Indexed because the
 * admin activity metrics and the recently-active filter are range scans
 * over exactly this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
