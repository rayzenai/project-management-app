<?php

namespace App\Queries;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The workspace activity feed: every audit entry the caller is allowed to see,
 * newest first, paginated.
 *
 * Visibility rides on the task's project, so a private project a user is not
 * on never leaks through the feed. The observers write every entry with
 * `is_public = false`, so filtering on that column would empty the feed —
 * never add a `->public()` here.
 */
class ActivityFeedQuery
{
    /**
     * @return LengthAwarePaginator<int, ProjectActivity>
     */
    public function get(Request $request, int $perPage = 40): LengthAwarePaginator
    {
        /** @var User $user */
        $user = $request->user();

        return ProjectActivity::query()
            ->whereHas(
                'task',
                fn (Builder $q) => $q->whereIn('project_id', Project::query()->visibleTo($user)->select('id')),
            )
            ->with(['user', 'task.project'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The row shape both delivery surfaces render.
     *
     * @return array<string, mixed>
     */
    public static function row(ProjectActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'description' => $activity->description,
            'user_name' => $activity->user?->name,
            'task_title' => $activity->task?->title,
            'task_slug' => $activity->task?->slug,
            'project_slug' => $activity->task?->project?->slug,
            'happened_at' => $activity->created_at?->toIso8601String(),
        ];
    }
}
