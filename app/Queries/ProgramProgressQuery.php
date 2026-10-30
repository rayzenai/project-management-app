<?php

namespace App\Queries;

use App\Http\Resources\ProgramResource;
use App\Models\Member;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A program's live progress, for its manager: one row per active project
 * (counted as {@see ProjectIndexQuery} counts them), then the report of
 * {@see ProgressReport} across every task of those projects.
 */
class ProgramProgressQuery
{
    /**
     * @return array<string, mixed>
     */
    public function data(Program $program): array
    {
        $today = CarbonImmutable::today();

        $projects = $program->projects()
            ->active()
            ->with(['teams.leaders'])
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn ($q) => $q->whereIn('status', Task::completeStatuses()),
                'tasks as overdue_tasks_count' => fn ($q) => $q->overdue(),
            ])
            ->withMin(['tasks as next_deadline_at' => fn ($q) => $q
                ->whereNotIn('status', Task::completeStatuses())
                ->where('deadline_at', '>=', $today)], 'deadline_at')
            ->orderBy('title')
            ->get();

        /** @var list<int> $projectIds */
        $projectIds = $projects->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $tasks = ProgressReport::tasks(Task::query()->whereIn('project_id', $projectIds));

        // Everyone assignable on these projects, plus anyone still holding
        // one of their tasks after leaving their teams; each once.
        $assignedIds = $tasks->flatMap(fn (Task $task) => $task->assignments->pluck('member_id'))->unique();
        $people = Member::query()
            ->where(function ($q) use ($projects, $assignedIds): void {
                foreach ($projects as $project) {
                    $q->orWhereIn('id', Member::query()->assignableFor($project)->select('members.id'));
                }
                $q->orWhereIn('id', $assignedIds);
            })
            ->with('organization:id,name')
            ->orderBy('name')
            ->get();

        $leaderIds = DB::table('member_team')
            ->whereIn('team_id', DB::table('project_team')->whereIn('project_id', $projectIds)->select('team_id'))
            ->where('role', 'leader')
            ->pluck('member_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($program->manager_id !== null) {
            $leaderIds[] = (int) $program->manager_id;
        }

        $rows = $projects->map(function (Project $project): array {
            $manager = $project->manager();
            $next = $project->getAttribute('next_deadline_at');

            return [
                'slug' => $project->slug,
                'title' => $project->title,
                'code' => $project->code,
                'manager' => $manager === null ? null : ['id' => $manager->id, 'name' => $manager->name],
                'tasks_count' => (int) $project->tasks_count,
                'done_tasks_count' => (int) $project->done_tasks_count,
                'overdue_tasks_count' => (int) $project->overdue_tasks_count,
                'next_deadline_at' => $next === null ? null : CarbonImmutable::parse((string) $next)->toIso8601String(),
            ];
        })->all();

        return [
            'program' => (new ProgramResource($program))->resolve(),
            'projects' => $rows,
            ...ProgressReport::build(
                $people,
                $tasks,
                fn (Member $member): string => in_array($member->id, $leaderIds, true) ? 'leader' : 'member',
                $projectIds,
            ),
        ];
    }
}
