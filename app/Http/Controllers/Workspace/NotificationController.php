<?php

namespace App\Http\Controllers\Workspace;

use App\Models\ProjectActivity;
use App\Models\User;
use App\Queries\ActivityFeedQuery;
use App\Queries\NotificationIndexQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Two tabs over the same screen: the personal inbox and the workspace
     * activity feed. Only the tab being shown is queried; the counts are
     * always resolved because both tab labels render them.
     */
    public function index(
        Request $request,
        NotificationIndexQuery $query,
        ActivityFeedQuery $activityQuery,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $tab = $request->query('tab') === 'activity' ? 'activity' : 'notifications';

        if ($tab === 'activity') {
            $activity = $activityQuery->get($request);

            return Inertia::render('Notifications/Index', [
                'tab' => $tab,
                'activity' => $activity->through(
                    fn (ProjectActivity $entry): array => ActivityFeedQuery::row($entry),
                ),
                'filters' => ['scope' => 'all', 'type' => null],
                'counts' => $query->counts($user),
            ]);
        }

        $payload = $query->get($request);

        return Inertia::render('Notifications/Index', [
            'tab' => $tab,
            'notifications' => $payload['notifications']->through(fn (DatabaseNotification $n): array => [
                'id' => $n->id,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
                'data' => $n->data,
            ]),
            'filters' => $payload['filters'],
            'counts' => $payload['counts'],
        ]);
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
