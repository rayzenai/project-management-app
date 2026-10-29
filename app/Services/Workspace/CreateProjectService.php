<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Project;
use App\Support\ProjectKeys;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates a project together with its team, led by its manager.
 */
class CreateProjectService
{
    public function __construct(private AppointProjectManagerService $appoint) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): ServiceResult
    {
        $title = trim((string) ($attributes['title'] ?? ''));
        if ($title === '') {
            return ServiceResult::failure('Title is required.', 422);
        }

        try {
            $project = DB::transaction(function () use ($attributes, $title): Project {
                $project = Project::create([
                    'organization_id' => $attributes['organization_id'],
                    'title' => $title,
                    'slug' => $this->uniqueSlug($title),
                    'key' => ProjectKeys::unique((int) $attributes['organization_id'], (string) ($attributes['key'] ?? ProjectKeys::suggest($title))),
                    'title_np' => $attributes['title_np'] ?? null,
                    'description' => $attributes['description'] ?? null,
                    'description_np' => $attributes['description_np'] ?? null,
                    'is_public' => (bool) ($attributes['is_public'] ?? false),
                ]);

                $team = $project->ensureTeam();
                $manager = Member::query()->find((int) ($attributes['manager_id'] ?? 0));

                if ($manager !== null) {
                    $this->appoint->execute($team, $manager);
                }

                return $project;
            });

            return ServiceResult::success($project, 'Project created.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'project';
        }
        $slug = $base;
        $i = 2;
        while (Project::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
