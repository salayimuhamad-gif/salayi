<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Services\DigestPreferences;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets a recipient choose their own delivery frequency (spec 22.2).
 *
 * `DigestPreferences` could read and write a frequency from the moment the
 * digest shipped, and nothing called `setFrequency()` — so the only way to be
 * put on the digest was for someone with database access to insert the row.
 * That is the same shape of defect the notification centre fixed: a capability
 * that exists, works, and is unreachable by the person it is for.
 *
 * Scoped to the authenticated user and nothing else. There is deliberately no
 * "set this user's frequency" administrative path: how often somebody is
 * contacted is their decision, and an admin screen for it is a lever for
 * increasing someone else's message volume.
 */
final class NotificationPreferencesController extends Controller
{
    public function __construct(
        private readonly DigestPreferences $preferences,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): Response
    {
        $userId = (int) $request->user()->id;
        $current = $this->preferences->settingsFor($userId);

        return Inertia::render('Admin/Notifications/Preferences', [
            'preferences' => [
                'frequency' => $current['frequency'],
                'digest_hour' => $current['digest_hour'],
            ],
            'options' => [
                'frequencies' => [
                    ['value' => DigestPreferences::IMMEDIATE, 'label' => __('notifications.preferences.immediate')],
                    ['value' => DigestPreferences::DAILY, 'label' => __('notifications.preferences.daily')],
                ],
                // 0–23. Erbil is one timezone, so an hour is enough and a
                // per-user timezone would be precision this product cannot use.
                'hours' => array_map(
                    static fn (int $h): array => [
                        'value' => $h,
                        'label' => sprintf('%02d:00', $h),
                    ],
                    range(0, 23),
                ),
            ],
            // Said plainly on the screen rather than discovered later: choosing
            // the digest does not delay a rejection or a security notice, and
            // someone deciding between the options deserves to know that.
            'never_batched_notice' => __('notifications.preferences.never_batched_notice'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'frequency' => ['required', 'string', 'in:'.DigestPreferences::IMMEDIATE.','.DigestPreferences::DAILY],
            'digest_hour' => ['required', 'integer', 'between:0,23'],
        ]);

        $userId = (int) $request->user()->id;

        $this->preferences->setFrequency(
            $userId,
            $validated['frequency'],
            (int) $validated['digest_hour'],
        );

        // Auditable because it changes how much contact someone receives, and
        // "I never asked to be put on the digest" is a complaint that needs an
        // answer with a timestamp on it.
        $this->audit->record('notification.preferences.updated', null, [], [
            'user_id' => $userId,
            'frequency' => $validated['frequency'],
            'digest_hour' => $validated['digest_hour'],
        ]);

        return back()->with('success', __('notifications.preferences.saved'));
    }
}
