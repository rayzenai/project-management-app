<?php

namespace App\Queries;

use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\TeamResource;
use App\Models\Project;
use App\Models\Team;

/**
 * Assembles the single-project payload (project, its tasks, and the team list)
 * shared by the Inertia web show page and the JSON API show endpoint.
 */
class ProjectShowQuery
{
    /**
     * @return array{project: array<string, mixed>, tasks: array<int, array<string, mixed>>, teams: array<int, array<string, mixed>>}
     */
    public function data(Project $project): array
    {
        $project->load(['teams:id', 'tasks' => fn ($q) => $q->with('assignments.member')->withCount(['notes', 'contacts'])]);

        return [
            'project' => (new ProjectResource($project))->resolve(),
            'tasks' => TaskResource::collection($project->tasks)->resolve(),
            'teams' => TeamResource::collection(Team::query()->orderBy('name')->get())->resolve(),
        ];
    }
}
