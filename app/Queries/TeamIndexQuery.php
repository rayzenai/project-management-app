<?php

namespace App\Queries;

use App\Http\Resources\MemberResource;
use App\Http\Resources\TeamResource;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;

/**
 * Assembles the team roster payload shared by the Inertia web index and the
 * JSON API index. Single source of truth for the teams + members assembly,
 * scoped to what the caller may see (Team / Member `visibleTo`).
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
        $organizationFilter = $isSuperAdmin ? ($request->integer('organization') ?: null) : null;

        $teams = Team::query()
            ->visibleTo($user)
            ->when($organizationFilter, fn ($q) => $q->where('organization_id', $organizationFilter))
            ->withCount('members')
            ->with(['members' => fn ($q) => $q->with('organization:id,name')->orderBy('name'), 'organization:id,name'])
            ->orderBy('name')
            ->get();

        $members = Member::query()
            ->visibleTo($user)
            ->when($organizationFilter, fn ($q) => $q->where('organization_id', $organizationFilter))
            ->with(['teams:id', 'user', 'organization:id,name'])
            ->orderBy('name')
            ->get();

        $teamRows = TeamResource::collection($teams)->resolve();

        foreach ($teams as $i => $team) {
            $canEdit = WorkspaceAccess::canEditTeam($user, $team);
            $canManageRoster = WorkspaceAccess::canManageRosterOf($user, $team);

            $teamRows[$i]['roster'] = $team->members->map(function (Member $member) use ($canEdit, $canManageRoster): array {
                /** @var object{role: string} $pivot */
                $pivot = $member->getRelation('pivot');
                $isLeader = $pivot->role === 'leader';

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'title' => $member->title,
                    'organization' => $member->organization?->name,
                    'is_active' => (bool) $member->is_active,
                    'role' => $isLeader ? 'leader' : 'member',
                    // Leaders are the office admin's call: a leader may take
                    // members off the team but not another leader.
                    'can_remove' => $canEdit || ($canManageRoster && ! $isLeader),
                ];
            })->values()->all();
        }

        $officeAdminOf = WorkspaceAccess::officeAdminOrganizationId($user);

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
            'canCreateTeam' => WorkspaceAccess::canCreateTeam($user),
            'canCreateMember' => $isSuperAdmin || $officeAdminOf !== null,
        ];
    }
}
