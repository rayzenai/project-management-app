<?php

namespace App\Queries;

use App\Models\User;
use App\Notifications\MentionedInComment;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskDeadlineDue;
use App\Notifications\TaskStatusChanged;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Assembles the notification inbox: a filtered page of notifications plus the
 * counts the filter chips render from.
 *
 * Filtering is by the `type` column (the notification class), not by digging
 * into `data->kind` — `notifications.data` is a text column, so a JSON path
 * would mean casting on every row.
 */
class NotificationIndexQuery
{
    /** Query-string value => notification class. */
    private const TYPES = [
        'assigned' => TaskAssigned::class,
        'status' => TaskStatusChanged::class,
        'mention' => MentionedInComment::class,
        'deadline' => TaskDeadlineDue::class,
    ];

    /**
     * Returns the paginator un-mapped: the Inertia page shapes rows one way and
     * the API wraps them in a NotificationResource, so neither shape belongs
     * here.
     *
     * @return array{
     *     notifications: LengthAwarePaginator<int, DatabaseNotification>,
     *     filters: array{scope: string, type: ?string},
     *     counts: array<string, int>
     * }
     */
    public function get(Request $request, int $perPage = 30): array
    {
        /** @var User $user */
        $user = $request->user();

        $scope = $request->query('scope') === 'unread' ? 'unread' : 'all';
        $typeKey = (string) $request->query('type', '');
        $type = self::TYPES[$typeKey] ?? null;

        $page = $user->notifications()
            ->when($scope === 'unread', fn (Builder $q): Builder => $q->whereNull('read_at'))
            ->when($type !== null, fn (Builder $q): Builder => $q->where('type', $type))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return [
            'notifications' => $page,
            'filters' => ['scope' => $scope, 'type' => $type === null ? null : $typeKey],
            'counts' => $this->counts($user),
        ];
    }

    /**
     * Totals for the chips: every notification, the unread ones, and one per
     * kind. Counted over the whole inbox, not the current page. Public because
     * the activity tab renders the same labels without paginating the inbox.
     *
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        // reorder() drops the relation's default ORDER BY created_at, which
        // Postgres rejects on a grouped query (SQLite tolerates it, so this
        // only shows up outside the test suite).
        $byType = $user->notifications()
            ->reorder()
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $counts = [
            'all' => (int) $byType->sum(),
            'unread' => $user->unreadNotifications()->count(),
        ];

        foreach (self::TYPES as $key => $class) {
            $counts[$key] = (int) ($byType[$class] ?? 0);
        }

        return $counts;
    }
}
