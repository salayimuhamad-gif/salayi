<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Models\TelegramLoginIntent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prune spent and expired login material.
 *
 * Two tables grow forever without this. Neither is dangerous while it grows —
 * an expired intent is already refused and a webhook event is only a ledger
 * entry — but on shared hosting an unbounded table is a slow query waiting to
 * happen, and login is the worst place to discover one.
 *
 * The retention windows differ because the tables answer different questions.
 * An intent is spent the moment it is consumed and keeps nothing worth reading
 * afterwards; the consent evidence it produced lives on the Consent row, which
 * is never pruned. A webhook event is replay protection, so it must outlive any
 * retry Telegram might still attempt — 48 hours is well beyond Telegram's own
 * retry window and cheap to keep.
 */
final class PruneTelegramIntents extends Command
{
    protected $signature = 'mulkihawler:telegram:prune {--days=2 : Retain webhook events for this many days}';

    protected $description = 'Remove expired login intents and old webhook replay records';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        // Expired OR consumed: a consumed intent has done its job, and keeping
        // a spent token around is a credential nobody needs any more.
        $intents = TelegramLoginIntent::query()
            ->where(function ($query): void {
                $query->whereNotNull('consumed_at')
                    // H5: a cancelled intent died by the person's own hand
                    // and still carries an encrypted registration payload —
                    // it has even less reason to linger than an expired one.
                    ->orWhereNotNull('cancelled_at')
                    ->orWhere('expires_at', '<', now());
            })
            // One hour of grace so a just-completed sign-in can still be
            // polled by the browser that started it.
            ->where('updated_at', '<', now()->subHour())
            ->delete();

        $events = DB::table('telegram_webhook_events')
            ->where('received_at', '<', now()->subDays($days))
            ->delete();

        $this->info(sprintf('Pruned %d intent(s) and %d webhook event(s).', $intents, $events));

        return self::SUCCESS;
    }
}
