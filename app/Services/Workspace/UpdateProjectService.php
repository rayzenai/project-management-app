<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Project;
use App\Support\ProjectKeys;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Updates a project's details, and through it its team: the team keeps the
 * project's name, may be opened to other organizations, and gets the
 * manager chosen here.
 */
class UpdateProjectService
{
    public function __construct(private AppointProjectManagerService $appoint) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Project $project, array $attributes): ServiceResult
    {
        try {
            $result = DB::transaction(function () use ($project, $attributes): ?ServiceResult {
                $newKey = isset($attributes['key']) ? strtoupper((string) $attributes['key']) : null;

                // A key stays reserved to whoever held it, aliases included, whoever asks.
                if ($newKey !== null && ProjectKeys::isTaken((int) $project->organization_id, $newKey, $project->id)) {
                    return ServiceResult::failure('That key is already used in this organization.', 422);
                }

                foreach (['title', 'title_np', 'description', 'description_np', 'is_public'] as $key) {
                    if (array_key_exists($key, $attributes)) {
                        $project->{$key} = $attributes[$key];
                    }
                }

                if ($newKey !== null) {
                    $this->rename($project, $newKey);
                }

                $project->save();

                $team = $project->ensureTeam();
                $team->name = $project->title;

                if (array_key_exists('is_cross_organization', $attributes)) {
                    $team->is_cross_organization = (bool) $attributes['is_cross_organization'];
                }

                $team->save();

                $manager = array_key_exists('manager_id', $attributes)
                    ? Member::query()->find((int) $attributes['manager_id'])
                    : null;

                return $manager === null ? null : $this->appoint->execute($team, $manager);
            });

            if ($result !== null && ! $result->success) {
                return $result;
            }

            return ServiceResult::success($project->fresh(), 'Project updated.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }

    /**
     * Gives the project a new key; the old one becomes an alias so it keeps
     * resolving. Taking back one of its own aliases retires that alias.
     */
    private function rename(Project $project, string $key): void
    {
        if ($key === $project->key) {
            return;
        }

        $project->keyAliases()->where('key', $key)->delete();
        $project->keyAliases()->create(['organization_id' => $project->organization_id, 'key' => $project->key]);
        $project->key = $key;
    }
}
