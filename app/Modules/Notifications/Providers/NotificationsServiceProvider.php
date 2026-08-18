<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Leads\Support\ConsentGate;
use App\Modules\Notifications\Channels\DatabaseChannel;
use App\Modules\Notifications\Channels\TelegramChannel;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Operations\Services\AuditLogger;

/**
 * Notifications domain — roadmap Step 6 (spec 20, 22.3, 23).
 *
 * See docs/ROADMAP_STATUS.md for implemented versus scaffolded.
 */
final class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Notifications';
    }

    protected function roadmapStep(): int
    {
        return 6;
    }

    /**
     * Bind the dispatcher WITH ITS CHANNELS.
     *
     * Container auto-resolution would have produced a dispatcher with an empty
     * channel list, which does not fail — `dispatch()` simply reports
     * `channel_not_registered` for everything and returns. That is the worst
     * shape of bug available here: every send appears to succeed, nothing is
     * delivered, and the audit row records it in a field nobody reads.
     *
     * Singleton because registration is the whole point; a fresh instance per
     * resolution would be an empty one.
     */
    protected function registerModule(): void
    {
        $this->app->singleton(NotificationDispatcher::class, function ($app): NotificationDispatcher {
            $dispatcher = new NotificationDispatcher(
                $app->make(ConsentGate::class),
                $app->make(AuditLogger::class),
            );

            /*
             * Order matters: it is the default preference order in
             * `dispatch()`. Telegram first because it is the channel someone in
             * Erbil actually reads; the database channel is appended by the
             * dispatcher regardless, so the in-app record is written even when
             * every external transport is declined or unconfigured.
             *
             * TelegramChannel reports itself unavailable without a bot token,
             * so a deployment that has not configured one degrades cleanly to
             * in-app rather than throwing on every send.
             */
            $dispatcher->register($app->make(TelegramChannel::class));
            $dispatcher->register($app->make(DatabaseChannel::class));

            return $dispatcher;
        });
    }
}
