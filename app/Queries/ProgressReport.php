<?php

namespace App\Queries;

use App\Models\Member;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The body shared by the team and project progress pages: each person's
 * workload, the tasks laid out for a Gantt chart, and the latest activity on
 * them.
 *
 * Gantt bars run from when a task was created to when it was finished, or to
 * its deadline while it is open. Tasks carry no planned start date; the chart
 * moves a waiting task's start to after the work it depends on (`depends_on`).
 */
class ProgressReport
{
    public const TASK_LIMIT = 500;

    /**
     * The tasks to report on, with what the rows need.
     *
     * @param  Builder<Task>  $query
     * @return Collection<int, Task>
     */
    public static function tasks(Builder $query): Collection
    {
        return $query
            ->with(['project:id,slug,title,key', 'assignments.member:id,name', 'dependencies' => fn ($q) => $q->select('tasks.id')])
            ->orderBy('project_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::TASK_LIMIT)
            ->get();
    }

    /**
     * @param  Collection<int, Member>  $people  who to list, in order
     * @param  Collection<int, Task>  $tasks  from {@see self::tasks()}
     * @param  Closure(Member): string  $roleOf  'leader' or 'member'
     * @param  list<int>  $projectIds  whose activity to show, team changes included
     * @return array{members: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>, truncated: bool, activity: array<int, array<string, mixed>>, generated_at: string}
     */
    public static function build(Collection $people, Collection $tasks, Closure $roleOf, array $projectIds): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        /** @var Collection<int, ProjectAssignment> $assignments */
        $assignments = $tasks->flatMap(fn (Task $task) => $task->assignments->each(fn (ProjectAssignment $a) => $a->setRelation('task', $task)));

        $lastActive = ProjectActivity::query()
            ->whereIn('task_id', $tasks->pluck('id'))
            ->whereIn('user_id', $people->pluck('user_id')->filter())
            ->selectRaw('user_id, MAX(created_at) as last_at')
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id');

        $memberRows = $people->map(function (Member $member) use ($assignments, $lastActive, $today, $roleOf): array {
            $mine = $assignments->where('member_id', $member->id);
            $open = $mine->filter(fn (ProjectAssignment $a): bool => ! $a->task->isComplete());
            $done = $mine->count() - $open->count();
            $last = $member->user_id !== null ? $lastActive->get($member->user_id) : null;

            return [
                'id' => $member->id,
                'name' => $member->name,
                'title' => $member->title,
                'organization' => $member->organization?->name,
                'role' => $roleOf($member),
                'is_active' => (bool) $member->is_active,
                'assigned' => $mine->count(),
                'open' => $open->count(),
                'done' => $done,
                'overdue' => $open->filter(fn (ProjectAssignment $a): bool => $a->task->is_overdue)->count(),
                'due_this_week' => $open->filter(fn (ProjectAssignment $a): bool => $a->task->deadline_at !== null
                    && $a->task->deadline_at->betweenIncluded($today, $today->addDays(7)))->count(),
                'progress' => $mine->isEmpty()
                    ? null
                    : (int) round($mine->avg(fn (ProjectAssignment $a): int => $a->task->isComplete() ? 100 : (int) $a->personal_progress)),
                'last_active_at' => $last === null ? null : CarbonImmutable::parse((string) $last)->toIso8601String(),
            ];
        })->values()->all();

        $listedIds = $people->pluck('id')->all();

        $taskRows = $tasks->map(function (Task $task) use ($listedIds): array {
            $complete = $task->isComplete();
            $end = $complete ? ($task->completed_at ?? $task->deadline_at) : $task->deadline_at;

            return [
                'id' => $task->id,
                'slug' => $task->slug,
                'title' => $task->title,
                'number' => $task->number,
                'project' => ['slug' => $task->project->slug, 'title' => $task->project->title, 'code' => $task->project->code],
                'status' => $task->status,
                'priority' => $task->priority,
                'progress' => $complete ? 100 : (int) $task->progress,
                'is_complete' => $complete,
                'is_late' => $task->is_late,
                'is_overdue' => $task->is_overdue,
                'start' => $task->created_at?->toDateString(),
                'end' => $end?->toDateString(),
                'deadline_at' => $task->deadline_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                // Finish-to-start: the ids of tasks this one waits for.
                'depends_on' => $task->dependencies->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'assignees' => $task->assignments
                    ->map(fn (ProjectAssignment $a): array => [
                        'id' => $a->member_id,
                        'name' => $a->member->name ?? '',
                        'in_team' => in_array($a->member_id, $listedIds, true),
                    ])
                    ->values()
                    ->all(),
            ];
        })->values()->all();

        $activity = ProjectActivity::query()
            ->whereIn('project_id', $projectIds)
            ->codeVisibleTo(request()->user())
            ->with(['user', 'task.project', 'project'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ProjectActivity $a): array => ActivityFeedQuery::row($a))
            ->values()
            ->all();

        return [
            'members' => $memberRows,
            'tasks' => $taskRows,
            'truncated' => $tasks->count() >= self::TASK_LIMIT,
            'activity' => $activity,
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
