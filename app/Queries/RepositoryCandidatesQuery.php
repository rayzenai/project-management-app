<?php

namespace App\Queries;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Project;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Services\Git\ForgeShapes;

/**
 * Repositories a project can link: those of its organization's forge
 * organization that no project links yet, matched by name in any case.
 */
class RepositoryCandidatesQuery
{
    public const LIMIT = 50;

    /**
     * @return list<array{full_name: string, name: string, description: ?string, html_url: ?string}>
     *
     * @throws ForgejoException when the forge can't list them
     */
    public function get(Project $project, string $term): array
    {
        $connection = GitConnection::query()->where('organization_id', $project->organization_id)->first();

        if ($connection === null) {
            return [];
        }

        $linked = GitRepository::query()->where('git_connection_id', $connection->id)->pluck('forge_id')->map(fn (mixed $id): int => (int) $id)->all();
        $term = mb_strtolower(trim($term));
        $candidates = [];

        foreach (ForgejoClient::for($connection)->organizationRepositories($connection->owner) as $repository) {
            $name = ForgeShapes::string($repository, 'name');
            $fullName = ForgeShapes::string($repository, 'full_name');

            if ($name === null || $fullName === null || in_array((int) ($repository['id'] ?? 0), $linked, true)) {
                continue;
            }

            if ($term === '' || str_contains(mb_strtolower($name), $term)) {
                $candidates[] = [
                    'full_name' => $fullName,
                    'name' => $name,
                    'description' => ForgeShapes::string($repository, 'description'),
                    'html_url' => ForgeShapes::string($repository, 'html_url'),
                ];
            }
        }

        usort($candidates, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return array_slice($candidates, 0, self::LIMIT);
    }
}
