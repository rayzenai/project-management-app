<?php

namespace App\Queries;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Assembles the workspace dashboard payload shared by the Inertia web view and
 * the JSON API feed. Single source of truth for the headline stats, per-project
 * rollups, status breakdowns, and the recent activity stream.
 */
class DashboardQuery
{
    /**
     * @return array{stats: array<string, int>, status_breakdown: list<array<string, mixed>>, projects: list<array<string, mixed>>, recent_activity: list<array<string, mixed>>}
     */
    public function get(Request $request): array
    {
        $statuses = collect((array) config('project-management.statuses'));
        $today = now()->startOfDay();
        $weekEnd = $today->copy()->addDays(7);
        $stalledBefore = now()->subDays(14);

        $user = $request->user();
        $visibleProjectIds = Project::query()->visibleTo($user)->pluck('id');

        $tasks = Task::query()->forActiveProjects()->whereIn('project_id', $visibleProjectIds)->get(['id', 'project_id', 'status', 'progress', 'status_updated_at', 'deadline_at']);
        $projects = Project::query()->visibleTo($user)->active()->orderBy('title')->get(['id', 'slug', 'title']);
        $byProject = $tasks->groupBy('project_id');

        $isComplete = fn (Task $t): bool => $t->isComplete();
        $isStalled = fn (Task $t): bool => ! $isComplete($t)
            && $t->status_updated_at !== null
            && $t->status_updated_at->lt($stalledBefore);
        $isDueThisWeek = fn (Task $t): bool => ! $isComplete($t)
            && $t->deadline_at !== null
            && $t->deadline_at->gte($today)
            && $t->deadline_at->lte($weekEnd);
        $isOverdue = fn (Task $t): bool => ! $isComplete($t)
            && $t->deadline_at !== null
            && $t->deadline_at->lt($today);

        $percent = function (Collection $items) use ($isComplete): int {
            $total = $items->count();
            if ($total === 0) {
                return 0;
            }

            return (int) round($items->filter($isComplete)->count() / $total * 100);
        };

        $breakdown = function (Collection $items) use ($statuses): array {
            $counts = $items->countBy('status');

            return array_values($statuses->map(fn (array $meta, string $value): array => [
                'value' => $value,
                'label' => $meta['label'] ?? $value,
                'color' => $meta['color'] ?? '#9CA3AF',
                'count' => (int) ($counts[$value] ?? 0),
            ])->all());
        };

        $projectRows = array_values($projects->map(function (Project $project) use ($byProject, $percent, $isStalled, $isDueThisWeek, $isOverdue, $breakdown): array {
            $items = $byProject->get($project->id, new Collection);

            return [
                'slug' => $project->slug,
                'title' => $project->title,
                'title_np' => $project->title_np,
                'tasks_count' => $items->count(),
                'percent_complete' => $percent($items),
                'stalled' => $items->filter($isStalled)->count(),
                'due_this_week' => $items->filter($isDueThisWeek)->count(),
                'overdue' => $items->filter($isOverdue)->count(),
                'status_breakdown' => $breakdown($items),
            ];
        })->all());

        // Scoped to the projects this user can see, NOT to ->public(): nothing
        // ever sets is_public on an activity row (ProjectActivityRecorder always
        // writes false), so that scope guaranteed an empty feed.
        $recentActivity = array_values(ProjectActivity::query()
            ->whereHas('task', fn (Builder $q) => $q->whereIn('project_id', $visibleProjectIds))
            ->recent(14)
            ->with(['user', 'task.project'])
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (ProjectActivity $activity): array => [
                'id' => $activity->id,
                'description' => $activity->description,
                'user_name' => $activity->user?->name,
                'task_title' => $activity->task?->title,
                'task_slug' => $activity->task?->slug,
                'project_slug' => $activity->task?->project?->slug,
                'happened_at' => $activity->created_at?->toIso8601String(),
            ])
            ->all());

        return [
            'stats' => [
                'projects' => $projects->count(),
                'tasks' => $tasks->count(),
                'percent_complete' => $percent($tasks),
                'due_this_week' => $tasks->filter($isDueThisWeek)->count(),
                'stalled' => $tasks->filter($isStalled)->count(),
                'overdue' => $tasks->filter($isOverdue)->count(),
            ],
            'status_breakdown' => $breakdown($tasks),
            'projects' => $projectRows,
            'recent_activity' => $recentActivity,
        ];
    }
}
