<?php

namespace App\Support;

use App\Models\Member;
use App\Models\Project;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Who may be given a task of a project, the one rule quick add and the
 * task's assignment route share: everyone Member::scopeAssignableFor allows,
 * exactly as before, and, for whoever runs the project's team, any active
 * person of its organization, who joins the team first
 * ({@see WorkspaceAccess::canAssignOutsideTeam()}).
 */
final class Assignees
{
    /**
     * The people of $memberIds who must join the project's team before they
     * are assigned, or null when any of them may not be assigned here.
     *
     * @param  list<int>  $memberIds
     * @return list<Member>|null
     */
    public static function joiners(?Authenticatable $user, Project $project, array $memberIds): ?array
    {
        if ($memberIds === []) {
            return [];
        }

        $assignable = Member::assignableFor($project)->whereKey($memberIds)->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
        $others = array_values(array_diff(array_unique($memberIds), $assignable));

        if ($others === []) {
            return [];
        }

        $members = Member::query()->whereKey($others)->get();

        if ($members->count() !== count($others)) {
            return null;
        }

        foreach ($members as $member) {
            if (! WorkspaceAccess::canAssignOutsideTeam($user, $project, $member)) {
                return null;
            }
        }

        return array_values($members->all());
    }

    /**
     * The people the user may give the project's tasks to only by bringing
     * them onto its team: active people of its organization who are not on
     * it, for whoever manages its roster; none for anyone else. The list twin
     * of WorkspaceAccess::canAssignOutsideTeam; keep them in step.
     *
     * @return Collection<int, Member>
     */
    public static function joinCandidates(?Authenticatable $user, Project $project): Collection
    {
        $team = $project->team();

        if ($team === null || ! WorkspaceAccess::canManageRosterOfProject($user, $project)) {
            return new Collection;
        }

        return Member::query()
            ->active()
            ->where('organization_id', $project->organization_id)
            ->whereDoesntHave('teams', fn ($q) => $q->where('teams.id', $team->getKey()))
            ->orderBy('name')
            ->get(['id', 'name', 'title']);
    }
}
