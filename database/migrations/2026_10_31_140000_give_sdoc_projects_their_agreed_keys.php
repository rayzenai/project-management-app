<?php

use App\Models\Organization;
use App\Models\Project;
use App\Services\Workspace\UpdateProjectService;
use App\Support\ProjectKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Gives SDOC's projects the keys SDOC agreed on. Only a key the app assigned
 * on its own is replaced (the old one stays as an alias); a key a person chose,
 * or one already taken, is left alone. Never throws: it runs on every deploy.
 *
 * The organization and the keys are copied here, not read from
 * SdocProjectsSeeder, so a later edit or rename of the seeder can neither
 * change nor break what this migration did.
 */
return new class extends Migration
{
    private const ORGANIZATION = 'SDOC';

    /** @var array<string, string> title => project key */
    private const PROJECTS = [
        'SMS Gateway' => 'SG',
        'eGP v2' => 'EGP',
        'GIWMS v2' => 'GIWMS',
        'SuTRA' => 'SUTRA',
        'GIOMS' => 'GIOMS',
        'Hello Sarkar' => 'HS',
        'Nagarik App' => 'NAGARIK',
        'Open Source OS with Office Packages' => 'OSOP',
    ];

    public function up(): void
    {
        $organization = Organization::query()->where('name', self::ORGANIZATION)->first();

        if ($organization === null) {
            return;
        }

        $update = app(UpdateProjectService::class);

        foreach (self::PROJECTS as $title => $key) {
            $project = Project::query()
                ->where('organization_id', $organization->id)
                ->where('title', $title)
                ->first();

            if ($project === null || $project->key === $key || ! ProjectKeys::isAutomatic($title, (string) $project->key)) {
                continue;
            }

            $result = $update->execute($project, ['key' => $key]);

            if (! $result->success) {
                Log::warning("Could not give {$title} the key {$key}: {$result->message}");
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: the aliases keep every old key resolving, and
        // renaming back is an admin's call.
    }
};
