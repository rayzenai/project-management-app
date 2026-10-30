<?php

namespace App\Queries;

use App\Http\Resources\ProgramResource;
use App\Http\Resources\ProjectResource;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Assembles the project list payload shared by the Inertia web index and the
 * JSON API index: each project with its manager, program, team size and how
 * its work stands (the page groups the ones in a program under it and the
 * rest by manager), the programs the viewer may open, the archived-count
 * badge, and the create-form context.
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
        $today = CarbonImmutable::today();

        $projects = Project::query()
            ->visibleTo($user)
            ->with(['organization:id,name', 'program:id,slug,name', 'teams' => fn ($q) => $q->withCount('members')->with('leaders')])
            ->when($archivedView, fn ($q) => $q->archived(), fn ($q) => $q->active())
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

        $rows = ProjectResource::collection($projects)->resolve();

        foreach ($projects as $i => $project) {
            $total = (int) $project->tasks_count;
            $rows[$i]['percent_complete'] = $total === 0
                ? 0
                : (int) round((int) $project->done_tasks_count / $total * 100);
            $rows[$i]['open_tasks_count'] = $total - (int) $project->done_tasks_count;
            $rows[$i]['overdue_tasks_count'] = (int) $project->overdue_tasks_count;
            $next = $project->getAttribute('next_deadline_at');
            $rows[$i]['next_deadline_at'] = $next === null ? null : CarbonImmutable::parse((string) $next)->toIso8601String();
        }

        $isSuperAdmin = WorkspaceAccess::isSuperAdmin($user);
        $officeAdminOf = WorkspaceAccess::officeAdminOrganizationId($user);
        $canCreate = WorkspaceAccess::canCreateProject($user);
        // Anyone else who may create is a program manager: only in their own
        // programs, managed by default by themselves.
        $programRequired = $canCreate && ! $isSuperAdmin && $officeAdminOf === null;
        $self = $programRequired ? WorkspaceAccess::memberFor($user) : null;

        // Who may manage a new project: active people of the organization it
        // lands in (a super-admin's list is narrowed by the chosen one; a
        // program manager's programs are all in their own).
        $managerCandidates = ! $canCreate ? [] : Member::query()
            ->active()
            ->whereNotNull('organization_id')
            ->when(! $isSuperAdmin, fn ($q) => $q->where('organization_id', $officeAdminOf ?? $self?->organization_id))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'title', 'organization_id'])
            ->map(fn (Member $m): array => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'title' => $m->title, 'organization_id' => $m->organization_id])
            ->all();

        // The programs the viewer may open: the page links their headings.
        $programs = Program::query()
            ->visibleTo($user)
            ->with(['organization:id,name', 'manager:id,name,title'])
            ->withCount('projects')
            ->orderBy('name')
            ->get();

        return [
            'projects' => $rows,
            'programs' => ProgramResource::collection($programs)->resolve(),
            'canManagePrograms' => WorkspaceAccess::canManagePrograms($user, $officeAdminOf),
            'archivedView' => $archivedView,
            'archivedCount' => Project::query()->visibleTo($user)->archived()->count(),
            'managerCandidates' => $managerCandidates,
            'canCreate' => $canCreate,
            'programRequired' => $programRequired,
            'defaultManagerId' => $self?->id,
            'isSuperAdmin' => $isSuperAdmin,
            'canPublish' => $isSuperAdmin || $officeAdminOf !== null,
            'organizations' => $isSuperAdmin
                ? Organization::query()->orderBy('name')->get(['id', 'name'])->map(fn (Organization $o): array => ['id' => $o->id, 'name' => $o->name])->all()
                : [],
        ];
    }
}
