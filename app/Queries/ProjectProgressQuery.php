<?php

namespace App\Queries;

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * A project's live progress, for the people who run it (leaders of its
 * teams, whoever administers its organization): the workload of everyone
 * who can be given its tasks or holds one, its tasks laid out for a Gantt
 * chart, and the latest activity on them (see {@see ProgressReport}).
 */
class ProjectProgressQuery
{
    /**
     * @return array<string, mixed>
     */
    public function data(Project $project): array
    {
        $project->loadMissing('organization:id,name');

        $tasks = ProgressReport::tasks(Task::query()->where('project_id', $project->id));

        // Its people, plus anyone still holding one of its tasks after
        // leaving its teams.
        $assignedIds = $tasks->flatMap(fn (Task $task) => $task->assignments->pluck('member_id'))->unique();
        $people = Member::query()
            ->where(fn ($q) => $q
                ->whereIn('id', Member::query()->assignableFor($project)->select('members.id'))
                ->orWhereIn('id', $assignedIds))
            ->with('organization:id,name')
            ->orderBy('name')
            ->get();

        $leaderIds = DB::table('member_team')
            ->whereIn('team_id', $project->teams()->select('teams.id'))
            ->where('role', 'leader')
            ->pluck('member_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [
            'project' => [
                'id' => $project->id,
                'slug' => $project->slug,
                'title' => $project->title,
                'title_np' => $project->title_np,
                'code' => $project->code,
                'organization' => $project->organization?->name,
                'is_archived' => $project->archived_at !== null,
            ],
            ...ProgressReport::build(
                $people,
                $tasks,
                fn (Member $member): string => in_array($member->id, $leaderIds, true) ? 'leader' : 'member',
            ),
        ];
    }
}
