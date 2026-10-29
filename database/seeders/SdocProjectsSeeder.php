<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Project;
use App\Services\Workspace\CreateProjectService;
use App\Services\Workspace\UpdateProjectService;
use App\Support\ProjectKeys;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * SDOC and the projects it has taken on, each with its own team. Managers
 * and people are added in the app afterwards (People tab, Make manager).
 *
 * Not wired into DatabaseSeeder. Safe to run again, in production too: it
 * creates SDOC and its projects when missing, and sets each project's agreed
 * key only while the project still has its automatically assigned key (the
 * title's suggestion or a suffix variant), leaving a key an admin chose alone.
 *
 *     php artisan db:seed --class=SdocProjectsSeeder --force
 */
class SdocProjectsSeeder extends Seeder
{
    public const ORGANIZATION = 'SDOC';

    /** @var array<string, string> title => project key */
    public const PROJECTS = [
        'SMS Gateway' => 'SG',
        'eGP v2' => 'EGP',
        'GIWMS v2' => 'GIWMS',
        'SuTRA' => 'SUTRA',
        'GIOMS' => 'GIOMS',
        'Hello Sarkar' => 'HS',
        'Nagarik App' => 'NAGARIK',
        'Open Source OS with Office Packages' => 'OSOP',
    ];

    public function run(CreateProjectService $createProject, UpdateProjectService $updateProject): void
    {
        $organization = Organization::query()->firstOrCreate(['name' => self::ORGANIZATION]);

        foreach (self::PROJECTS as $title => $key) {
            $project = Project::query()
                ->where('organization_id', $organization->id)
                ->where('title', $title)
                ->first();

            if ($project !== null) {
                $project->ensureTeam();

                if ($project->key !== $key && ProjectKeys::isAutomatic($title, $project->key)) {
                    $result = $updateProject->execute($project, ['key' => $key]);

                    if (! $result->success) {
                        throw new RuntimeException("Could not rename the key of {$title}: {$result->message}");
                    }

                    $this->command->line("  rekeyed  {$title} -> {$key}");

                    continue;
                }

                $this->command->line("  kept     {$title}");

                continue;
            }

            $result = $createProject->execute(['organization_id' => $organization->id, 'title' => $title, 'key' => $key]);

            if (! $result->success) {
                throw new RuntimeException("Could not create {$title}: {$result->message}");
            }

            $this->command->line("  created  {$title}");
        }
    }
}
