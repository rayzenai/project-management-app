<?php

namespace App\Queries;

use App\Models\GitRepository;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * People the user may give access to a linked repository, found by name or
 * email: active people with an organization who are not its maintainers
 * and have no grant on it yet; for whoever administers the project's
 * organization its people and anyone on the project's team, for its
 * manager and its program's manager only people on its team. The list twin
 * of WorkspaceAccess::canGrantRepositoryAccessTo; keep them in step.
 * Returns a handful of matches, never a list, and no email.
 */
class RepositoryAccessCandidatesQuery
{
    public const LIMIT = 20;

    /**
     * @return list<array{id: int, name: string, title: string|null}>
     */
    public function get(?Authenticatable $user, GitRepository $repository, string $term): array
    {
        if (! WorkspaceAccess::canManageRepositoryAccess($user, $repository)) {
            return [];
        }

        $project = $repository->project;
        $like = '%'.mb_strtolower(str_replace(['%', '_'], '', trim($term))).'%';
        $onTeam = fn (Builder $q): Builder => $q->whereHas('teams.projects', fn (Builder $p): Builder => $p->where('projects.id', $project->getKey()));

        return array_values(Member::query()
            ->active()
            ->whereNotNull('organization_id')
            ->whereKeyNot(WorkspaceAccess::repositoryMaintainerIds($project))
            ->whereDoesntHave('repositoryGrants', fn (Builder $g): Builder => $g->where('git_repository_id', $repository->getKey()))
            ->where(fn (Builder $q): Builder => WorkspaceAccess::administers($user, $project->organization_id)
                ? $q->where('organization_id', $project->organization_id)->orWhere($onTeam)
                : $onTeam($q))
            ->where(fn (Builder $q): Builder => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'title'])
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'title' => $member->title,
            ])
            ->all());
    }
}
