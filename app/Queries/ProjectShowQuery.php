<?php

namespace App\Queries;

use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\Member;
use App\Models\Project;
use App\Support\WorkspaceAccess;

/**
 * Assembles the single-project payload (project, its tasks, and its team)
 * shared by the Inertia web show page and the JSON API show endpoint.
 */
class ProjectShowQuery
{
    /**
     * @return array{project: array<string, mixed>, tasks: array<int, array<string, mixed>>, team: array<string, mixed>|null}
     */
    public function data(Project $project): array
    {
        $project->load([
            'teams' => fn ($q) => $q->withCount('members')->with(['leaders', 'members' => fn ($m) => $m->with('organization:id,name')->orderBy('name')]),
            'tasks' => fn ($q) => $q->with(['assignments.member', 'dependencies', 'dependents'])->withCount(['notes', 'contacts']),
        ]);

        return [
            'project' => (new ProjectResource($project))->resolve(),
            'tasks' => TaskResource::collection($project->tasks)->resolve(),
            'team' => $this->team($project),
        ];
    }

    /**
     * The project's team: its manager first, then everyone by name.
     *
     * @return array<string, mixed>|null
     */
    private function team(Project $project): ?array
    {
        $team = $project->team();

        if ($team === null) {
            return null;
        }

        $user = request()->user();
        $canChooseManager = WorkspaceAccess::canEditTeam($user, $team);
        $canManage = WorkspaceAccess::canManageRosterOf($user, $team);

        $members = $team->members
            ->map(function (Member $member) use ($canChooseManager, $canManage): array {
                /** @var object{role: string} $pivot */
                $pivot = $member->getRelation('pivot');
                $isManager = $pivot->role === 'leader';

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'title' => $member->title,
                    'organization' => $member->organization?->name,
                    'is_active' => (bool) $member->is_active,
                    'is_manager' => $isManager,
                    // The manager leaves only once someone else manages.
                    'can_remove' => ! $isManager && ($canChooseManager || $canManage),
                ];
            })
            ->sortBy(fn (array $m): string => ($m['is_manager'] ? '0' : '1').mb_strtolower($m['name']))
            ->values()
            ->all();

        return [
            'id' => $team->id,
            'is_cross_organization' => (bool) $team->is_cross_organization,
            'members' => $members,
            'can_manage' => $canManage,
            'can_choose_manager' => $canChooseManager,
        ];
    }
}
