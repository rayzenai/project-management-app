<?php

namespace App\Queries;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Program;
use App\Models\Project;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Services\Git\ForgeShapes;
use App\Support\ProjectKeys;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * Repositories on a connection's forge organization that no project links,
 * so their commits count nowhere (repositories made straight on the forge).
 * Archived ones are left out; the newest activity comes first.
 *
 * The forge's list is cached per connection for TTL_SECONDS, a failure too
 * (so a forge that is down is asked at most once per TTL), and forgotten
 * whenever a repository of the connection is linked or unlinked
 * (GitRepositoryObserver). Only the pages that show it ask the forge
 * (for()); the sidebar's Maintenance count reads the cache alone (cached()). Linked ones are taken off at every read, so a
 * cached list never offers a repository linked since. A write never trusts
 * the cache: find() asks the forge.
 *
 * @phpstan-type Unlinked array{forge_id: int, full_name: string, name: string, description: ?string, html_url: ?string, updated_at: ?string}
 */
class UnlinkedRepositoriesQuery
{
    /** How long the forge's list is kept (the Redis rules: every key expires). */
    public const TTL_SECONDS = 300;

    /**
     * Asks the forge when the list isn't cached, and caches its answer. Only
     * the pages that show the list call this (Code activity, Maintenance).
     *
     * @return array{unavailable: bool, repositories: list<Unlinked>}
     */
    public function for(GitConnection $connection): array
    {
        /** @var array{unavailable: bool, repositories: list<Unlinked>} $forge */
        $forge = Cache::remember(self::key((int) $connection->id), self::TTL_SECONDS, function () use ($connection): array {
            try {
                return ['unavailable' => false, 'repositories' => $this->fromForge($connection)];
            } catch (ForgejoException) {
                return ['unavailable' => true, 'repositories' => []];
            }
        });

        return $this->withoutLinked($connection, $forge);
    }

    /**
     * The cached list only, never the forge: null when nothing is cached.
     * For the sidebar's Maintenance count, which runs on every page.
     *
     * @return array{unavailable: bool, repositories: list<Unlinked>}|null
     */
    public function cached(GitConnection $connection): ?array
    {
        /** @var array{unavailable: bool, repositories: list<Unlinked>}|null $forge */
        $forge = Cache::get(self::key((int) $connection->id));

        return is_array($forge) ? $this->withoutLinked($connection, $forge) : null;
    }

    /**
     * The unlinked repositories of every connection the viewer may see them
     * for (WorkspaceAccess::canSeeUnlinkedRepositories), one organization's
     * when $organizationId is given, by organization name. Each repository
     * carries the title and key a new project for it would start with, and
     * each connection the projects it may be linked to and the programs a
     * new project may join.
     *
     * @return list<array{connection: array{id: int, organization: array{id: int, name: string}}, unavailable: bool, repositories: list<array<string, mixed>>, projects: list<array{id: int, slug: string, title: string, program: ?string, organization_id: int}>, programs: list<array{id: int, name: string}>}>
     */
    public function forViewer(?Authenticatable $user, ?int $organizationId = null): array
    {
        if ($user === null) {
            return [];
        }

        $connections = GitConnection::query()
            ->with('organization:id,name')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->get()
            ->filter(fn (GitConnection $connection): bool => WorkspaceAccess::canSeeUnlinkedRepositories($user, $connection))
            ->sortBy(fn (GitConnection $connection): string => mb_strtolower($connection->organization->name));

        $notices = [];

        foreach ($connections as $connection) {
            $organization = (int) $connection->organization_id;
            $found = $this->for($connection);
            $waiting = $found['repositories'] !== [];

            $notices[] = [
                'connection' => [
                    'id' => (int) $connection->id,
                    'organization' => ['id' => $organization, 'name' => $connection->organization->name],
                ],
                'unavailable' => $found['unavailable'],
                'repositories' => array_map(function (array $repository) use ($organization): array {
                    $title = self::titleFor($repository['name']);

                    return [
                        ...$repository,
                        'suggested_title' => $title,
                        'suggested_key' => ProjectKeys::unique($organization, ProjectKeys::suggest($title)),
                    ];
                }, $found['repositories']),
                'projects' => $waiting ? $this->linkableProjects($user, $organization) : [],
                'programs' => $waiting ? $this->programs($organization) : [],
            ];
        }

        return $notices;
    }

    /**
     * One repository, asked of the forge now (never the cache): null when it
     * is no longer waiting to be linked (gone, moved to another forge
     * organization, archived, or linked meanwhile).
     *
     * @return Unlinked|null
     *
     * @throws ForgejoException when the forge can't answer
     */
    public function find(GitConnection $connection, int $forgeId): ?array
    {
        try {
            $repository = ForgejoClient::for($connection)->repositoryById($forgeId);
        } catch (ForgejoException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        $owner = ForgeShapes::string(ForgeShapes::map($repository, 'owner'), 'login');
        $shaped = self::shape($repository);

        if ($shaped === null || $owner === null || mb_strtolower($owner) !== mb_strtolower($connection->owner)
            || in_array($forgeId, $this->linkedForgeIds($connection), true)) {
            return null;
        }

        return $shaped;
    }

    public static function forget(int $connectionId): void
    {
        Cache::forget(self::key($connectionId));
    }

    /**
     * What a new project for a repository is called: its name's words
     * title-cased, a short first word (2 to 4 letters) taken for an acronym:
     * `ird-prize` is "IRD Prize", `runner-smoke` "Runner Smoke".
     */
    public static function titleFor(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return $name;
        }

        $title = [];

        foreach ($words as $i => $word) {
            $title[] = $i === 0 && preg_match('/^[A-Za-z]{2,4}$/', $word) === 1 ? strtoupper($word) : mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
        }

        return implode(' ', $title);
    }

    private static function key(int $connectionId): string
    {
        return "git-unlinked-repositories:{$connectionId}";
    }

    /**
     * @return list<Unlinked>
     *
     * @throws ForgejoException
     */
    private function fromForge(GitConnection $connection): array
    {
        $repositories = [];

        foreach (ForgejoClient::for($connection)->organizationRepositories($connection->owner) as $repository) {
            $shaped = self::shape($repository);

            if ($shaped !== null) {
                $repositories[] = $shaped;
            }
        }

        usort($repositories, fn (array $a, array $b): int => (strtotime((string) $b['updated_at']) ?: 0) <=> (strtotime((string) $a['updated_at']) ?: 0)
            ?: strnatcasecmp($a['name'], $b['name']));

        return $repositories;
    }

    /**
     * An api.Repository as the notice lists it; null for an archived one or
     * one the forge describes too little to link.
     *
     * @param  array<string, mixed>  $repository
     * @return Unlinked|null
     */
    private static function shape(array $repository): ?array
    {
        $id = (int) ($repository['id'] ?? 0);
        $name = ForgeShapes::string($repository, 'name');
        $fullName = ForgeShapes::string($repository, 'full_name');

        if ($id <= 0 || $name === null || $fullName === null || ($repository['archived'] ?? false) === true) {
            return null;
        }

        return [
            'forge_id' => $id,
            'full_name' => $fullName,
            'name' => $name,
            'description' => ForgeShapes::string($repository, 'description'),
            'html_url' => ForgeShapes::string($repository, 'html_url'),
            'updated_at' => ForgeShapes::string($repository, 'updated_at'),
        ];
    }

    /**
     * @param  array{unavailable: bool, repositories: list<Unlinked>}  $forge
     * @return array{unavailable: bool, repositories: list<Unlinked>}
     */
    private function withoutLinked(GitConnection $connection, array $forge): array
    {
        $linked = array_flip($this->linkedForgeIds($connection));

        return [
            'unavailable' => $forge['unavailable'],
            'repositories' => array_values(array_filter($forge['repositories'], fn (array $repository): bool => ! isset($linked[$repository['forge_id']]))),
        ];
    }

    /**
     * @return list<int>
     */
    private function linkedForgeIds(GitConnection $connection): array
    {
        return array_values(GitRepository::query()
            ->where('git_connection_id', $connection->id)
            ->pluck('forge_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * The organization's active projects the viewer may link repositories
     * to, shaped for the project picker.
     *
     * @return list<array{id: int, slug: string, title: string, program: ?string, organization_id: int}>
     */
    private function linkableProjects(Authenticatable $user, int $organizationId): array
    {
        return array_values(Project::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->with('program:id,name')
            ->orderBy('title')
            ->get()
            ->filter(fn (Project $project): bool => WorkspaceAccess::canManageProjectRepositories($user, $project))
            ->map(fn (Project $project): array => [
                'id' => (int) $project->id,
                'slug' => $project->slug,
                'title' => $project->title,
                'program' => $project->program?->name,
                'organization_id' => (int) $project->organization_id,
            ])
            ->all());
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function programs(int $organizationId): array
    {
        return array_values(Program::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Program $program): array => ['id' => (int) $program->id, 'name' => $program->name])
            ->all());
    }
}
