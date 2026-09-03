<?php

namespace App\Services\Workspace;

use App\Models\Project;
use App\Support\ServiceResult;
use Throwable;

class UpdateProjectService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Project $project, array $attributes): ServiceResult
    {
        try {
            foreach (['title', 'title_np', 'description', 'description_np', 'is_public'] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $project->{$key} = $attributes[$key];
                }
            }

            $project->save();

            if (array_key_exists('team_ids', $attributes)) {
                $project->teams()->sync($attributes['team_ids'] ?? []);
            }

            return ServiceResult::success($project->fresh(), 'Project updated.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
