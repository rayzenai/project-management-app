<?php

use App\Models\Organization;
use App\Models\Project;
use App\Services\Workspace\UpdateProjectService;
use App\Support\ProjectKeys;
use Database\Seeders\SdocProjectsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Gives SDOC's projects the keys SDOC agreed on. Only a key the app assigned
 * on its own is replaced (the old one stays as an alias); a key a person chose,
 * or one already taken, is left alone. Never throws: it runs on every deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $organization = Organization::query()->where('name', SdocProjectsSeeder::ORGANIZATION)->first();

        if ($organization === null) {
            return;
        }

        $update = app(UpdateProjectService::class);

        foreach (SdocProjectsSeeder::PROJECTS as $title => $key) {
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
