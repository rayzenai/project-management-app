<?php

namespace App\Queries;

use App\Models\Member;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A team's live progress, for its leaders and whoever administers its
 * organization: each member's workload, every task of the team's active
 * projects laid out for a Gantt chart, and the latest activity on them.
 *
 * "The team's tasks" are the tasks of the projects the team has access to,
 * which is exactly what its leader can see; a member's work on another
 * team's projects is not shown here.
 *
 * Gantt bars run from when a task was created to when it was finished, or to
 * its deadline while it is open. Tasks carry no planned start date.
 */
class TeamProgressQuery
{
    public const TASK_LIMIT = 500;

    /**
     * @return array<string, mixed>
     */
    public function data(Team $team): array
    {
        $team->loadMissing('organization:id,name');
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        $members = $team->members()->with('organization:id,name')->orderBy('name')->get();

        $tasks = Task::query()
            ->whereIn('project_id', $team->projects()->whereNull('archived_at')->select('projects.id'))
            ->with(['project:id,slug,title', 'assignments.member:id,name'])
            ->orderBy('project_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::TASK_LIMIT)
            ->get();

        /** @var Collection<int, ProjectAssignment> $assignments */
        $assignments = $tasks->flatMap(fn (Task $task) => $task->assignments->each(fn (ProjectAssignment $a) => $a->setRelation('task', $task)));

        $lastActive = ProjectActivity::query()
            ->whereIn('task_id', $tasks->pluck('id'))
            ->whereIn('user_id', $members->pluck('user_id')->filter())
            ->selectRaw('user_id, MAX(created_at) as last_at')
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id');

        $memberRows = $members->map(function (Member $member) use ($assignments, $lastActive, $today): array {
            $mine = $assignments->where('member_id', $member->id);
            $open = $mine->filter(fn (ProjectAssignment $a): bool => ! $a->task->isComplete());
            $done = $mine->count() - $open->count();
            /** @var object{role: string} $pivot */
            $pivot = $member->getRelation('pivot');
            $last = $member->user_id !== null ? $lastActive->get($member->user_id) : null;

            return [
                'id' => $member->id,
                'name' => $member->name,
                'title' => $member->title,
                'organization' => $member->organization?->name,
                'role' => $pivot->role === 'leader' ? 'leader' : 'member',
                'is_active' => (bool) $member->is_active,
                'assigned' => $mine->count(),
                'open' => $open->count(),
                'done' => $done,
                'overdue' => $open->filter(fn (ProjectAssignment $a): bool => $a->task->deadline_at !== null && $a->task->deadline_at->lt($today))->count(),
                'due_this_week' => $open->filter(fn (ProjectAssignment $a): bool => $a->task->deadline_at !== null
                    && $a->task->deadline_at->betweenIncluded($today, $today->addDays(7)))->count(),
                'progress' => $mine->isEmpty()
                    ? null
                    : (int) round($mine->avg(fn (ProjectAssignment $a): int => $a->task->isComplete() ? 100 : (int) $a->personal_progress)),
                'last_active_at' => $last === null ? null : CarbonImmutable::parse((string) $last)->toIso8601String(),
            ];
        })->values()->all();

        $teamMemberIds = $members->pluck('id')->all();

        $taskRows = $tasks->map(function (Task $task) use ($teamMemberIds): array {
            $complete = $task->isComplete();
            $end = $complete ? ($task->completed_at ?? $task->deadline_at) : $task->deadline_at;

            return [
                'id' => $task->id,
                'slug' => $task->slug,
                'title' => $task->title,
                'item_number' => $task->item_number,
                'project' => ['slug' => $task->project->slug, 'title' => $task->project->title, 'code' => $task->project->code],
                'status' => $task->status,
                'priority' => $task->priority,
                'progress' => $complete ? 100 : (int) $task->progress,
                'is_complete' => $complete,
                'is_late' => $task->is_late,
                'start' => $task->created_at?->toDateString(),
                'end' => $end?->toDateString(),
                'deadline_at' => $task->deadline_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'assignees' => $task->assignments
                    ->map(fn (ProjectAssignment $a): array => [
                        'id' => $a->member_id,
                        'name' => $a->member->name ?? '',
                        'in_team' => in_array($a->member_id, $teamMemberIds, true),
                    ])
                    ->values()
                    ->all(),
            ];
        })->values()->all();

        $activity = ProjectActivity::query()
            ->whereIn('task_id', $tasks->pluck('id'))
            ->with(['user', 'task.project'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ProjectActivity $a): array => ActivityFeedQuery::row($a))
            ->all();

        return [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'organization' => $team->organization?->name,
                'is_cross_organization' => (bool) $team->is_cross_organization,
            ],
            'members' => $memberRows,
            'tasks' => $taskRows,
            'truncated' => $tasks->count() >= self::TASK_LIMIT,
            'activity' => $activity,
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
