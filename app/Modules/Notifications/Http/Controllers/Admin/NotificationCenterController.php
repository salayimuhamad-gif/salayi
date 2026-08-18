<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app notification centre (spec 22.1).
 *
 * `DatabaseChannel` is the fallback that exists so a notice is never lost when
 * Telegram is declined or SMTP is misconfigured. Without a screen reading the
 * table, that guarantee was the opposite of what it claimed: the message was
 * not lost, it was filed somewhere nobody could open.
 *
 * NOT PERMISSION-GATED, and that is deliberate. Notifications are the
 * recipient's own, so a permission check would be the wrong instrument — the
 * question is never "may this user read notifications" but "is this row theirs".
 * Every query answers the second question through `forUser()`, scoped to the
 * authenticated id and never to anything from the request.
 */
final class NotificationCenterController extends Controller
{
    /** Page size. Small enough that a phone on Erbil mobile data renders one screen quickly. */
    private const PER_PAGE = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;
        $filter = $request->string('filter')->toString() === 'unread' ? 'unread' : 'all';

        $notifications = Notification::query()
            ->forUser($userId)
            ->when($filter === 'unread', fn ($q) => $q->unread())
            // Unread first, then newest. A read notice from an hour ago must
            // not push an unread one from this morning off the first page.
            ->orderByRaw('read_at is null desc')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Notification $n): array => [
                'id' => $n->id,
                'key' => $n->key,
                'subject' => $n->subject,
                // A list preview, not the message. The full body carries the
                // reason and the unsubscribe line, which belong on the detail
                // screen rather than crammed into a row.
                'preview' => mb_substr(trim(explode("\n", $n->body)[0]), 0, 140),
                'priority' => $n->priority,
                'is_read' => $n->isRead(),
                'action_url' => $n->action_url,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Notifications/Index', [
            'notifications' => $notifications,
            'filters' => ['filter' => $filter],
            'counts' => [
                'unread' => Notification::query()->forUser($userId)->unread()->count(),
                'total' => Notification::query()->forUser($userId)->count(),
            ],
        ]);
    }

    /**
     * One notification, in full.
     *
     * Opening it marks it read. Requiring a separate button to say "yes, I have
     * now read the thing I am looking at" is the sort of interaction that leaves
     * a badge permanently at 3.
     */
    public function show(Request $request, int $notification): Response
    {
        $userId = (int) $request->user()->id;

        // findOrFail on a query already scoped to the owner. A 404 rather than
        // a 403 for someone else's row: telling an attacker that id 812 exists
        // but is not theirs is information they did not have.
        $model = Notification::query()
            ->forUser($userId)
            ->findOrFail($notification);

        $model->markRead();

        return Inertia::render('Admin/Notifications/Show', [
            'notification' => [
                'id' => $model->id,
                'key' => $model->key,
                'subject' => $model->subject,
                'body' => $model->body,
                'reason' => $model->reason(),
                'unsubscribe_url' => $model->unsubscribeUrl(),
                'action_url' => $model->action_url,
                'priority' => $model->priority,
                'channel' => $model->channel,
                'locale' => $model->locale,
                'created_at' => $model->created_at?->toIso8601String(),
                'read_at' => $model->read_at?->toIso8601String(),
            ],
            'counts' => [
                'unread' => Notification::query()->forUser($userId)->unread()->count(),
            ],
        ]);
    }

    public function markRead(Request $request, int $notification): RedirectResponse
    {
        $model = Notification::query()
            ->forUser((int) $request->user()->id)
            ->findOrFail($notification);

        $model->markRead();

        return back()->with('success', __('notifications.center.marked_one'));
    }

    /**
     * Mark everything read.
     *
     * A single bulk UPDATE rather than a loop of saves: a user returning from
     * two weeks away may have hundreds of rows, and on Hostinger the request
     * has roughly fifty seconds before the worker is killed.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $userId = (int) $request->user()->id;

        $changed = Notification::query()
            ->forUser($userId)
            ->unread()
            ->update(['read_at' => now(), 'updated_at' => now()]);

        if ($changed > 0) {
            $this->audit->record('notification.marked_all_read', null, [], [
                'user_id' => $userId,
                'count' => $changed,
            ]);
        }

        return back()->with('success', __('notifications.center.marked_all'));
    }
}
