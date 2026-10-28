<?php

namespace App\Queries;

use App\Models\Member;
use App\Models\Task;
use App\Models\Team;

/**
 * A team's live progress, for its leaders and whoever administers its
 * organization: each member's workload, every task of the team's active
 * projects laid out for a Gantt chart, and the latest activity on them
 * (see {@see ProgressReport}).
 *
 * "The team's tasks" are the tasks of the projects the team has access to,
 * which is exactly what its leader can see; a member's work on another
 * team's projects is not shown here.
 */
class TeamProgressQuery
{
    /**
     * @return array<string, mixed>
     */
    public function data(Team $team): array
    {
        $team->loadMissing('organization:id,name');

        $members = $team->members()->with('organization:id,name')->orderBy('name')->get();

        $tasks = ProgressReport::tasks(
            Task::query()->whereIn('project_id', $team->projects()->whereNull('archived_at')->select('projects.id')),
        );

        return [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'organization' => $team->organization?->name,
                'is_cross_organization' => (bool) $team->is_cross_organization,
            ],
            ...ProgressReport::build($members, $tasks, function (Member $member): string {
                /** @var object{role: string} $pivot */
                $pivot = $member->getRelation('pivot');

                return $pivot->role === 'leader' ? 'leader' : 'member';
            }),
        ];
    }
}
