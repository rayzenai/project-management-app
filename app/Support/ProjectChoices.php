<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Every list where someone chooses a project to put something in, in the
 * one shape the project picker reads (resources/js/components/ProjectPicker.svelte):
 * a new task, a new form, a form's copy and its tasks, where new documents
 * go, moving a document, linking a repository. `mine` (the viewer is on
 * its team) is what the picker's "Your projects" shows; `organizations`
 * fill its dropdown. Which projects belong in a list stays with the list.
 */
final class ProjectChoices
{
    /**
     * @param  iterable<Project>  $projects  in the order to show, `program` loaded
     * @return array{projects: list<array{id: int, slug: string, title: string, program: ?string, organization_id: int, mine: bool}>, organizations: list<array{id: int, name: string}>}
     */
    public static function for(Authenticatable $viewer, iterable $projects): array
    {
        $projects = collect($projects)->values();

        if ($projects->isEmpty()) {
            return ['projects' => [], 'organizations' => []];
        }

        $member = WorkspaceAccess::memberFor($viewer);
        $mine = $member === null ? [] : array_flip(DB::table('project_team')
            ->join('member_team', 'member_team.team_id', '=', 'project_team.team_id')
            ->where('member_team.member_id', $member->getKey())
            ->whereIn('project_team.project_id', $projects->map(fn (Project $project): mixed => $project->getKey())->all())
            ->pluck('project_team.project_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());

        return [
            'projects' => array_values($projects
                ->map(fn (Project $project): array => self::row($project, isset($mine[(int) $project->getKey()])))
                ->all()),
            'organizations' => self::organizations($projects->pluck('organization_id')),
        ];
    }

    /**
     * One project as the picker reads it.
     *
     * @return array{id: int, slug: string, title: string, program: ?string, organization_id: int, mine: bool}
     */
    public static function row(Project $project, bool $mine): array
    {
        return [
            'id' => (int) $project->getKey(),
            'slug' => $project->slug,
            'title' => $project->title,
            'program' => $project->program?->name,
            'organization_id' => (int) $project->organization_id,
            'mine' => $mine,
        ];
    }

    /**
     * These organizations by name, each once, in one query.
     *
     * @param  iterable<mixed>  $ids
     * @return list<array{id: int, name: string}>
     */
    public static function organizations(iterable $ids): array
    {
        $ids = collect($ids)->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();

        return $ids->isEmpty() ? [] : array_values(Organization::query()
            ->whereKey($ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Organization $organization): array => ['id' => (int) $organization->id, 'name' => $organization->name])
            ->all());
    }
}
