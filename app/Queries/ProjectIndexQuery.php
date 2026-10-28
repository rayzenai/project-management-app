<?php

namespace App\Queries;

use App\Http\Resources\ProjectResource;
use App\Http\Resources\TeamResource;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;

/**
 * Assembles the project list payload shared by the Inertia web index and the
 * JSON API index. Single source of truth for visibility scoping, active/archived
 * filtering, the archived-count badge, and the create-form context.
 */
class ProjectIndexQuery
{
    /**
     * @return array<string, mixed>
     */
    public function data(Request $request): array
    {
        $user = $request->user();
        $archivedView = $request->boolean('archived');

        $projects = Project::query()
            ->visibleTo($user)
            ->with('organization:id,name')
            ->when($archivedView, fn ($q) => $q->archived(), fn ($q) => $q->active())
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn ($q) => $q->whereIn('status', Task::completeStatuses()),
            ])
            ->orderBy('title')
            ->get();

        $rows = ProjectResource::collection($projects)->resolve();

        foreach ($projects as $i => $project) {
            $total = (int) $project->tasks_count;
            $rows[$i]['percent_complete'] = $total === 0
                ? 0
                : (int) round((int) $project->done_tasks_count / $total * 100);
        }

        // Teams the creator may give access to: any (filtered client-side by
        // the chosen organization) for a super-admin, their organization's for
        // an office admin, otherwise the teams they lead.
        $officeAdminOf = WorkspaceAccess::officeAdminOrganizationId($user);
        $assignableTeams = Team::query()
            ->when(! WorkspaceAccess::isSuperAdmin($user), fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('id', WorkspaceAccess::ledTeamIds($user))
                ->when($officeAdminOf !== null, fn ($q) => $q->orWhere('organization_id', $officeAdminOf))))
            ->with('organization:id,name')
            ->orderBy('name')
            ->get();

        return [
            'projects' => $rows,
            'archivedView' => $archivedView,
            'archivedCount' => Project::query()->visibleTo($user)->archived()->count(),
            'assignableTeams' => TeamResource::collection($assignableTeams)->resolve(),
            'canCreate' => WorkspaceAccess::canCreateProject($user),
            'isSuperAdmin' => WorkspaceAccess::isSuperAdmin($user),
            'canPublish' => WorkspaceAccess::isSuperAdmin($user) || $officeAdminOf !== null,
            'organizations' => WorkspaceAccess::isSuperAdmin($user)
                ? Organization::query()->orderBy('name')->get(['id', 'name'])->map(fn (Organization $o): array => ['id' => $o->id, 'name' => $o->name])->all()
                : [],
        ];
    }
}
