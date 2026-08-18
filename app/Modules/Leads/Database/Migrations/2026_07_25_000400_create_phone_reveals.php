<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Reveal ledger (File one §9 Leads.3).
 *
 * §9 asks for "Phone Reveal with permission, consent, reason, and audit". Three
 * of those four already had homes — the permission system, the Consent model
 * and the audit log. The fourth, REASON, had nowhere to live, and without it
 * the other three describe an event nobody can interpret afterwards.
 *
 * This table is the answer to a question a regulator, a customer or a manager
 * will eventually ask: *who looked at this person's phone number, when, and
 * why?* An audit-log line saying "agent 14 revealed lead 302" answers the first
 * two. It does not answer the third, and the third is the one that
 * distinguishes an agent following up a callback request from an agent
 * exporting a list.
 *
 * The number itself is never stored here. It lives encrypted on the user row;
 * copying it into a ledger — the very table built to prove it was handled
 * carefully — would put a plaintext number somewhere the encryption does not
 * reach, and would make this table a more attractive target than the one it
 * audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_reveals', function (Blueprint $table): void {
            $table->id();

            // Who looked.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * Whose number. Polymorphic because a reveal may target a lead, a
             * portfolio owner making an enquiry, or a company contact — and
             * spec conventions require a morph map rather than raw class names.
             */
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');

            /*
             * The reason, typed AND free-text. The enum makes reveals
             * aggregatable ("83% were callback_requested"); the note makes an
             * individual one explicable. Neither alone is enough: a dropdown
             * with no note cannot justify an unusual case, and free text alone
             * cannot be reported on.
             */
            $table->string('reason_code', 32);
            $table->text('reason_note')->nullable();

            /*
             * The consent that permitted it, recorded by id rather than as a
             * boolean. A boolean says somebody believed there was consent; a
             * foreign key says which consent, granted when, and by what route —
             * and it breaks visibly if that consent is later withdrawn.
             */
            $table->foreignId('consent_id')->nullable()->constrained('consents')->nullOnDelete();

            // Context, for spotting patterns rather than identifying people.
            $table->string('ip_hash', 64)->nullable();
            $table->string('company_id')->nullable();

            $table->timestamp('revealed_at');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'revealed_at']);
            // Supports "how many reveals did this agent perform today", which
            // is the query a rate limit and an abuse review both need.
            $table->index('revealed_at');
        });
    }

    public function down(): void
    {
        /*
         * MIGRATION-GUARD: intentional-drop — reversing this migration's own
         * table.
         *
         * Worth stating plainly: this table is a compliance record. Rolling
         * this migration back destroys the evidence of who accessed whose
         * contact details. Export it before doing so in any environment that
         * has served real leads.
         */
        Schema::dropIfExists('phone_reveals');
    }
};
