<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use App\Modules\Core\Contracts\FeatureFlagRepository;
use Illuminate\Support\Facades\Log;

/**
 * Feature-flag guard for queued jobs and scheduled commands.
 *
 * Repair prompt §3.5 (EN) "scheduled jobs for disabled features must not run"
 * and §17 (AR) "prevent background jobs when the feature is disabled".
 *
 * HTTP middleware cannot help here: the scheduler has no request, so a cron
 * tick would happily send the digests of a disabled alerts module, or refresh
 * data for a switched-off places database. On shared hosting that is also
 * wasted execution budget inside a fifty-second window.
 *
 * The check is made at RUN time, never at dispatch time. A job may sit in the
 * queue for minutes while an administrator turns the feature off, and the
 * value that matters is the one at the moment work would actually happen.
 *
 * Usage in a job or command:
 *
 *     use GuardedByFeature;
 *
 *     public function handle(): void
 *     {
 *         if ($this->featureDisabled('alerts.telegram')) {
 *             return;
 *         }
 *         // ...
 *     }
 */
trait GuardedByFeature
{
    /**
     * True when the work should be skipped because its feature is off.
     *
     * Skipping is logged, not silent. A digest command that quietly does
     * nothing for a week looks identical to one that is broken, and the
     * difference matters at three in the morning.
     */
    protected function featureDisabled(string ...$features): bool
    {
        /** @var FeatureFlagRepository $flags */
        $flags = app(FeatureFlagRepository::class);

        foreach ($features as $feature) {
            if ($flags->enabled($feature)) {
                continue;
            }

            Log::channel('security')->info('background.skipped_feature_disabled', [
                'feature' => $feature,
                'job' => static::class,
            ]);

            return true;
        }

        return false;
    }

    /** Convenience inverse, for readability at call sites. */
    protected function featureEnabled(string ...$features): bool
    {
        return ! $this->featureDisabled(...$features);
    }

    /**
     * True when at least ONE of the named features is enabled.
     *
     * For work served by interchangeable channels: the notification digest is
     * worth running if email OR Telegram OR push is on, and worth skipping only
     * when a recipient could not be reached by any route. Requiring all three
     * would silence the digest for a site that had deliberately chosen a single
     * channel.
     */
    protected function featureAnyEnabled(string ...$features): bool
    {
        /** @var FeatureFlagRepository $flags */
        $flags = app(FeatureFlagRepository::class);

        foreach ($features as $feature) {
            if ($flags->enabled($feature)) {
                return true;
            }
        }

        Log::channel('security')->info('background.skipped_all_features_disabled', [
            'features' => $features,
            'job' => static::class,
        ]);

        return false;
    }
}
