<?php

namespace App\Queries;

use App\Http\Resources\MemberResource;
use App\Http\Resources\TeamResource;
use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use App\Support\WorkspaceOrganization;
use Illuminate\Http\Request;

/**
 * Assembles the People page payload shared by the Inertia web index and the
 * JSON API index: the people the caller may see, and the project teams they
 * may see (each a project's own, carrying that project), scoped through
 * Team / Member `visibleTo`.
 *
 * Each team carries its own `roster` so a cross-organization team can show
 * its outside members without putting them in the caller's people list.
 * A super-admin may narrow everything to one organization (`?organization=`).
 */
class TeamIndexQuery
{
    /**
     * @return array<string, mixed>
     */
    public function data(Request $request): array
    {
        $user = $request->user();
        $isSuperAdmin = WorkspaceAccess::isSuperAdmin($user);
        $organizationFilter = $isSuperAdmin ? $this->organizationFilter($request) : null;

        $teams = Team::query()
            ->visibleTo($user)
            ->when($organizationFilter, fn ($q) => $q->where('organization_id', $organizationFilter))
            ->withCount('members')
            ->with(['members' => fn ($q) => $q->with(['organization:id,name', 'designation:id,name'])->orderBy('name'), 'organization:id,name', 'projects:id,slug,title,program_id,archived_at', 'projects.program:id,name'])
            ->orderBy('name')
            ->get();

        $members = Member::query()
            ->visibleTo($user)
            ->when($organizationFilter, fn ($q) => $q->where('organization_id', $organizationFilter))
            ->with([...MemberResource::ROLE_RELATIONS, ...MemberResource::STRUCTURE_RELATIONS, 'user', 'organization:id,name'])
            ->orderBy('name')
            ->get();

        $teamRows = TeamResource::collection($teams)->resolve();

        foreach ($teams as $i => $team) {
            $canEdit = WorkspaceAccess::canEditTeam($user, $team);
            $canManageRoster = WorkspaceAccess::canManageRosterOf($user, $team);
            $canRemoveManager = WorkspaceAccess::canRemoveManager($user, $team);
            $project = $team->project();
            $teamRows[$i]['project'] = $project === null ? null : [
                'slug' => $project->slug,
                'title' => $project->title,
                'program' => $project->program?->name,
                'is_archived' => $project->archived_at !== null,
            ];

            $teamRows[$i]['roster'] = $team->members->map(function (Member $member) use ($canEdit, $canManageRoster, $canRemoveManager): array {
                /** @var object{role: string} $pivot */
                $pivot = $member->getRelation('pivot');
                $isLeader = $pivot->role === 'leader';

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'designation' => $member->designation?->name,
                    'organization' => $member->organization?->name,
                    'is_active' => (bool) $member->is_active,
                    'role' => $isLeader ? 'leader' : 'member',
                    // Only whoever may take the manager off removes them.
                    'can_remove' => $isLeader ? $canRemoveManager : ($canEdit || $canManageRoster),
                ];
            })->values()->all();
        }

        $officeAdminOf = WorkspaceAccess::officeAdminOrganizationId($user);
        $canSetRoles = WorkspaceAccess::canSetRolesIn($user, $officeAdminOf);
        $canSetStructure = $isSuperAdmin || WorkspaceAccess::canManageStructure($user, $officeAdminOf);

        return [
            'teams' => $teamRows,
            'members' => MemberResource::collection($members)->resolve(),
            'organizations' => $isSuperAdmin
                ? Organization::query()->orderBy('name')->get(['id', 'name'])->map(fn (Organization $o): array => ['id' => $o->id, 'name' => $o->name])->all()
                : [],
            'organizationFilter' => $organizationFilter,
            'officeAdminOrganization' => $officeAdminOf === null
                ? null
                : Organization::query()->whereKey($officeAdminOf)->first(['id', 'name'])?->only(['id', 'name']),
            'canCreateMember' => $isSuperAdmin || $officeAdminOf !== null,
            // The person form's role fields: whoever may manage people sets
            // them, from the organizations they administer.
            'canSetRoles' => $canSetRoles,
            'roleOptions' => $canSetRoles ? $this->roleOptions($isSuperAdmin ? null : $officeAdminOf) : ['programs' => [], 'projects' => []],
            // The person form's designation and "reports to" fields: whoever
            // may change the organization's structure. "Reports to" offers
            // the organization's active people from `members`.
            'canSetStructure' => $canSetStructure,
            'designationOptions' => $canSetStructure ? $this->designationOptions($isSuperAdmin ? null : $officeAdminOf) : [],
        ];
    }

    /**
     * The designations a person can be given, for one organization (null:
     * all of them), most senior first.
     *
     * @return list<array{id: int, organization_id: int, name: string}>
     */
    private function designationOptions(?int $organizationId): array
    {
        return array_values(Designation::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('rank')
            ->orderBy('name')
            ->get(['id', 'organization_id', 'name'])
            ->map(fn (Designation $designation): array => [
                'id' => $designation->id,
                'organization_id' => $designation->organization_id,
                'name' => $designation->name,
            ])
            ->all());
    }

    /**
     * The programs and active projects a role can be given on, each with
     * its manager now, for one organization (null: all of them). The form
     * shows those of the person's organization. A constant number of
     * queries, however many people there are.
     *
     * @return array{programs: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>}
     */
    private function roleOptions(?int $organizationId): array
    {
        $programs = Program::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->with('manager:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Program $program): array => [
                'id' => $program->id,
                'name' => $program->name,
                'organization_id' => $program->organization_id,
                'manager' => $program->manager?->only(['id', 'name']),
            ]);

        $projects = Project::query()
            ->active()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->with(['program:id,name', 'teams:id', 'teams.leaders:id,name'])
            ->orderBy('title')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'title' => $project->title,
                'program' => $project->program?->name,
                'organization_id' => $project->organization_id,
                'team_id' => $project->team()?->id,
                'manager' => $project->manager()?->only(['id', 'name']),
            ]);

        return ['programs' => $programs->values()->all(), 'projects' => $projects->values()->all()];
    }

    /**
     * A super-admin's People: the organization picked on the page (`all`
     * for everyone's), else the one their workspace is switched to (none:
     * everyone's).
     */
    private function organizationFilter(Request $request): ?int
    {
        $picked = $request->query('organization');

        if ($picked === 'all') {
            return null;
        }

        if (is_string($picked) && ctype_digit($picked) && (int) $picked > 0) {
            return (int) $picked;
        }

        return WorkspaceOrganization::chosen($request->user());
    }
}
