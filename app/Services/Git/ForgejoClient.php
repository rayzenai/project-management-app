<?php

namespace App\Services\Git;

use App\Models\GitConnection;
use App\Models\GitWebhookDelivery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A small Forgejo API client on Laravel's HTTP client.
 */
class ForgejoClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public static function for(GitConnection $connection): self
    {
        return new self($connection->base_url, $connection->token);
    }

    /**
     * @return array{id: int, login: string}
     */
    public function currentUser(): array
    {
        $data = $this->get('/user');

        // A 200 that is not a Forgejo user (an HTML page, another app's JSON).
        if (! is_string($data['login'] ?? null) || $data['login'] === '') {
            throw new ForgejoException('The address did not answer like a Forgejo forge.', 404);
        }

        return ['id' => (int) ($data['id'] ?? 0), 'login' => $data['login']];
    }

    /**
     * A renamed organization answers its old name with a redirect to the new
     * one, which is not followed: under the name asked for, it owns nothing.
     *
     * @return array{is_owner: bool}
     */
    public function organizationPermissions(string $org, string $username): array
    {
        $response = $this->send('get', '/users/'.rawurlencode($username).'/orgs/'.rawurlencode($org).'/permissions', redirects: false);
        $data = $response->redirect() ? [] : $this->json($response);

        return ['is_owner' => (bool) ($data['is_owner'] ?? false)];
    }

    /**
     * Takes the person out of the forge organization (and its teams). 204 and
     * 404 (already not a member, or no such user) both mean it is done.
     */
    public function removeOrganizationMember(string $org, string $username): void
    {
        $this->send('delete', '/orgs/'.rawurlencode($org).'/members/'.rawurlencode($username), null, [404]);
    }

    /**
     * Creates the organization webhook and returns its id.
     */
    public function createOrganizationHook(string $org, string $url, string $secret): int
    {
        $data = $this->json($this->send('post', '/orgs/'.rawurlencode($org).'/hooks', [
            'type' => 'forgejo',
            ...self::hookSettings($url, $secret),
        ]));

        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            throw new ForgejoException('The forge did not return a webhook id.', 502);
        }

        return $id;
    }

    /**
     * The organization webhook as the forge holds it (api.Hook: `active`,
     * `branch_filter`, `events`, and `config` without the secret), or null
     * when it is gone (404).
     *
     * @return array<string, mixed>|null
     */
    public function organizationHook(string $org, int $id): ?array
    {
        $response = $this->send('get', '/orgs/'.rawurlencode($org).'/hooks/'.$id, null, [404]);

        if ($response->status() === 404) {
            return null;
        }

        $data = $this->json($response);

        // A 200 that is not this hook (an HTML page, another app's JSON).
        if ((int) ($data['id'] ?? 0) !== $id) {
            throw new ForgejoException('The forge did not answer like a Forgejo webhook.', 502);
        }

        return $data;
    }

    /**
     * Puts every setting of the organization webhook back as
     * createOrganizationHook made it, with $secret.
     */
    public function updateOrganizationHook(string $org, int $id, string $url, string $secret): void
    {
        $this->send('patch', '/orgs/'.rawurlencode($org).'/hooks/'.$id, self::hookSettings($url, $secret));
    }

    /**
     * 404 (already gone) is fine.
     */
    public function deleteOrganizationHook(string $org, int $id): void
    {
        $this->send('delete', '/orgs/'.rawurlencode($org).'/hooks/'.$id, null, [404]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function organizationRepositories(string $org, int $max = 500): array
    {
        return $this->paged('/orgs/'.rawurlencode($org).'/repos', [], $max);
    }

    /**
     * @return array<string, mixed>
     */
    public function repository(string $owner, string $name): array
    {
        return $this->json($this->send('get', $this->repoPath($owner, $name)));
    }

    /**
     * A repository by its forge id, which follows renames and transfers. 404
     * (deleted, or moved where the token can't see it) throws ForgejoException 404.
     *
     * @return array<string, mixed>
     */
    public function repositoryById(int $id): array
    {
        $data = $this->get('/repositories/'.$id);

        // A 200 that is not this repository (an HTML page, another app's JSON).
        if ((int) ($data['id'] ?? 0) !== $id) {
            throw new ForgejoException('The forge did not answer like a Forgejo repository.', 502);
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function branches(string $owner, string $name, int $max = 200): array
    {
        return $this->paged($this->repoPath($owner, $name).'/branches', [], $max);
    }

    /**
     * The ref's history, newest first. Forgejo 15 ignores `since`, so with
     * one the paging stops after the first page that reaches back past it;
     * that page can still hold older commits, for the caller to filter.
     *
     * @return list<array<string, mixed>>
     */
    public function commits(string $owner, string $name, string $ref, ?CarbonInterface $since = null, int $max = 500): array
    {
        $query = ['sha' => $ref, 'stat' => 'false', 'files' => 'false', 'verification' => 'false'];
        $reachedBack = null;
        if ($since !== null) {
            $query['since'] = $since->toIso8601String();
            $reachedBack = function (array $commit) use ($since): bool {
                $date = ForgeShapes::commitDate($commit);

                return $date !== null && CarbonImmutable::parse($date)->lessThan($since);
            };
        }

        return $this->paged($this->repoPath($owner, $name).'/commits', $query, $max, $reachedBack);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pullRequests(string $owner, string $name, int $max = 200): array
    {
        return $this->paged($this->repoPath($owner, $name).'/pulls', ['state' => 'all', 'sort' => 'recentupdate'], $max);
    }

    /**
     * Creates a private, empty repository (default branch `main`) in the forge
     * organization and returns its JSON. A taken name is a 409.
     *
     * @return array<string, mixed>
     */
    public function createOrganizationRepository(string $org, string $name, ?string $description): array
    {
        return $this->json($this->send('post', '/orgs/'.rawurlencode($org).'/repos', [
            'name' => $name,
            'description' => $description ?? '',
            'private' => true,
            'auto_init' => false,
            'default_branch' => 'main',
        ]));
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function protectBranch(string $owner, string $name, array $rule): void
    {
        $this->send('post', $this->repoPath($owner, $name).'/branch_protections', $rule);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateBranchProtection(string $owner, string $name, string $rule, array $changes): void
    {
        $this->send('patch', $this->repoPath($owner, $name).'/branch_protections/'.rawurlencode($rule), $changes);
    }

    /**
     * @return list<string> logins
     */
    public function collaborators(string $owner, string $name, int $max = 500): array
    {
        return $this->logins($this->paged($this->repoPath($owner, $name).'/collaborators', [], $max));
    }

    /**
     * Adds the collaborator, or changes the permission of an existing one
     * (204 both ways). A login the forge has no user for is a 422.
     */
    public function setCollaborator(string $owner, string $name, string $login, string $permission): void
    {
        $this->send('put', $this->collaboratorPath($owner, $name, $login), ['permission' => $permission]);
    }

    /**
     * The forge answers 204 for a collaborator that is not there as well.
     */
    public function removeCollaborator(string $owner, string $name, string $login): void
    {
        $this->send('delete', $this->collaboratorPath($owner, $name, $login));
    }

    public function collaboratorPermission(string $owner, string $name, string $login): string
    {
        $data = $this->get($this->collaboratorPath($owner, $name, $login).'/permission');

        return is_string($data['permission'] ?? null) ? $data['permission'] : '';
    }

    /**
     * One commit with its `parents` and `stats` {total, additions, deletions}.
     *
     * @return array<string, mixed>
     */
    public function commit(string $owner, string $name, string $sha): array
    {
        return $this->json($this->send(
            'get',
            $this->repoPath($owner, $name).'/git/commits/'.rawurlencode($sha),
            query: ['stat' => 'true', 'verification' => 'false', 'files' => 'false'],
        ));
    }

    /**
     * The commit's raw unified diff, or null when it is over $maxBytes (by its
     * Content-Length when the forge sends one, else by the body read).
     */
    public function commitDiff(string $owner, string $name, string $sha, int $maxBytes): ?string
    {
        $response = $this->send('get', $this->repoPath($owner, $name).'/git/commits/'.rawurlencode($sha).'.diff');

        $length = $response->header('Content-Length');
        if ($length !== '' && (int) $length > $maxBytes) {
            return null;
        }

        $body = $response->body();

        return strlen($body) > $maxBytes ? null : $body;
    }

    /**
     * Makes the forge link KEY-12 in commits and pull requests back to the tracker.
     */
    public function setExternalTracker(string $owner, string $name, string $format): void
    {
        $this->send('patch', $this->repoPath($owner, $name), [
            'has_issues' => true,
            'external_tracker' => [
                'external_tracker_url' => (string) config('app.url'),
                'external_tracker_format' => $format,
                'external_tracker_style' => 'alphanumeric',
            ],
        ]);
    }

    /**
     * Switches the repository back to the forge's own issue tracker: without
     * `external_tracker`, Forgejo turns its built-in one on and drops the
     * external one (routers/api/v1/repo/repo.go, updateRepoUnits).
     */
    public function useInternalTracker(string $owner, string $name): void
    {
        $this->send('patch', $this->repoPath($owner, $name), ['has_issues' => true]);
    }

    /**
     * @return list<string> logins
     */
    public function organizationMembers(string $org): array
    {
        return $this->logins($this->paged('/orgs/'.rawurlencode($org).'/members', [], PHP_INT_MAX));
    }

    public function ownersTeamId(string $org): int
    {
        foreach ($this->paged('/orgs/'.rawurlencode($org).'/teams', [], PHP_INT_MAX) as $team) {
            if (is_string($team['name'] ?? null) && mb_strtolower($team['name']) === 'owners') {
                return (int) ($team['id'] ?? 0);
            }
        }

        throw new ForgejoException('The forge organization has no Owners team.', 404);
    }

    /**
     * @return list<string> logins
     */
    public function teamMembers(int $team): array
    {
        return $this->logins($this->paged('/teams/'.$team.'/members', [], PHP_INT_MAX));
    }

    public function addTeamMember(int $team, string $user): void
    {
        $this->send('put', '/teams/'.$team.'/members/'.rawurlencode($user));
    }

    /**
     * 404 (not a member) is fine.
     */
    public function removeTeamMember(int $team, string $user): void
    {
        $this->send('delete', '/teams/'.$team.'/members/'.rawurlencode($user), null, [404]);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->json($this->send('get', $path));
    }

    /**
     * Sends one call. Any failure, except the statuses in $tolerate, is a
     * ForgejoException; the message never carries the token or the payload.
     *
     * @param  array<string, mixed>|null  $body
     * @param  list<int>  $tolerate
     * @param  array<string, scalar>  $query  sent with GET only
     */
    private function send(string $method, string $path, ?array $body = null, array $tolerate = [], array $query = [], bool $redirects = true): Response
    {
        $request = $this->request();

        if (! $redirects) {
            $request->withoutRedirecting();
        }

        try {
            $response = $request->{$method}($path, $method === 'get' ? $query : ($body ?? []));
        } catch (ConnectionException $e) {
            throw new ForgejoException('The forge could not be reached.', 0, $e);
        }

        if ($response->failed() && ! in_array($response->status(), $tolerate, true)) {
            throw new ForgejoException('The forge answered '.$response->status().'.', $response->status());
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Pages through a list endpoint (limit=50) until $max rows. The forge may
     * cap a page below 50, so a short page is not the end: it ends on an empty
     * page, or on a Link header without rel="next". At most 200 pages, so a
     * forge that ignores `page` cannot loop forever. With $last, it also ends
     * after a page whose last row it accepts.
     *
     * @param  array<string, string>  $query
     * @param  (Closure(array<string, mixed>): bool)|null  $last
     * @return list<array<string, mixed>>
     */
    private function paged(string $path, array $query, int $max, ?Closure $last = null): array
    {
        $rows = [];

        for ($page = 1; $page <= 200 && count($rows) < $max; $page++) {
            $response = $this->send('get', $path, null, [], [...$query, 'page' => $page, 'limit' => 50]);
            $data = $this->json($response);

            foreach ($data as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $link = $response->header('Link');
            if ($data === [] || ($link !== '' && ! str_contains($link, 'rel="next"'))) {
                break;
            }

            if ($last !== null && $rows !== [] && $last($rows[array_key_last($rows)])) {
                break;
            }
        }

        return array_slice($rows, 0, $max);
    }

    /**
     * @return array{active: true, branch_filter: string, events: list<string>, config: array{url: string, content_type: string, secret: string}}
     */
    private static function hookSettings(string $url, string $secret): array
    {
        return [
            'active' => true,
            'branch_filter' => '*',
            'events' => GitWebhookDelivery::EVENTS,
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return list<string>
     */
    private function logins(array $users): array
    {
        $logins = [];
        foreach ($users as $user) {
            if (is_string($user['login'] ?? null) && $user['login'] !== '') {
                $logins[] = $user['login'];
            }
        }

        return $logins;
    }

    private function repoPath(string $owner, string $name): string
    {
        return '/repos/'.rawurlencode($owner).'/'.rawurlencode($name);
    }

    private function collaboratorPath(string $owner, string $name, string $login): string
    {
        return $this->repoPath($owner, $name).'/collaborators/'.rawurlencode($login);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/api/v1')
            ->withHeaders(['Authorization' => 'token '.$this->token])
            ->acceptJson()
            ->timeout(10);
    }
}
