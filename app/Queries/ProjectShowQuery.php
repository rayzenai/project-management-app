<?php

namespace App\Queries;

use App\Http\Resources\GitRepositoryResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\Member;
use App\Models\Program;
use App\Models\Project;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Assembles the single-project payload (project, its tasks, its team, when
 * its organization has git its code, and for its administrators the
 * programs it can move to) shared by the Inertia web show page and the JSON
 * API show endpoint.
 */
class ProjectShowQuery
{
    /** Open pull requests shown per repository on the Code tab. */
    private const OPEN_PULL_REQUESTS = 10;

    /**
     * @return array{project: array<string, mixed>, tasks: array<int, array<string, mixed>>, team: array<string, mixed>|null, git: array{repositories: list<array<string, mixed>>, can_manage: bool}|null, programs: array<int, array{id: int, slug: string, name: string}>}
     */
    public function data(Project $project): array
    {
        $viewer = request()->user();
        $canViewCode = WorkspaceAccess::canViewProjectCode($viewer, $project);

        $project->load([
            'program:id,slug,name',
            'teams' => fn ($q) => $q->withCount('members')->with(['leaders', 'members' => fn ($m) => $m->with('organization:id,name')->orderBy('name')]),
            'tasks' => fn ($q) => $q->with(['assignments.member', 'dependencies', 'dependents'])->withCount(['notes', 'contacts'])
                // The board's pull-request mark, only where code shows at all,
                // and only for pull requests the viewer may see.
                ->when($canViewCode, fn ($q) => $q->withExists(['pullRequests' => fn (Builder $pr) => TaskCodeQuery::inVisibleRepositories($pr, $viewer)])),
        ]);

        return [
            'project' => (new ProjectResource($project))->resolve(),
            'tasks' => TaskResource::collection($project->tasks)->resolve(),
            'team' => $this->team($project),
            'git' => $canViewCode ? $this->git($project, $viewer) : null,
            // Edit project's Program choices, for whoever may move it.
            'programs' => WorkspaceAccess::administers($viewer, $project->organization_id)
                ? Program::query()->where('organization_id', $project->organization_id)->orderBy('name')->get(['id', 'slug', 'name'])
                    ->map(fn (Program $program): array => ['id' => $program->id, 'slug' => $program->slug, 'name' => $program->name])
                    ->all()
                : [],
        ];
    }

    /**
     * The Code tab: the linked repositories, each with its newest open pull
     * requests, and whether the viewer may link and unlink them.
     *
     * @return array{repositories: list<array<string, mixed>>, can_manage: bool}
     */
    private function git(Project $project, ?Authenticatable $viewer): array
    {
        $repositories = $project->repositories()
            ->with(['openPullRequests' => fn ($q) => $q->orderByDesc('opened_at')->orderByDesc('id')->limit(self::OPEN_PULL_REQUESTS)])
            ->withCount('openPullRequests')
            ->orderBy('full_name')
            ->get();

        return [
            'repositories' => array_values(GitRepositoryResource::collection($repositories)->resolve()),
            'can_manage' => WorkspaceAccess::canManageProjectRepositories($viewer, $project),
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
