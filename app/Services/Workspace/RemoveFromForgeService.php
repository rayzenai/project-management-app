<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Services\Git\ForgeShapes;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes a deactivated or deleted person out of every connected forge
 * organization, then off the collaborators of every repository of each of
 * those organizations, linked to a project or not (an unlinked repository,
 * or one linked through a connection since re-pointed, keeps the
 * collaborators the tracker gave it). It lists each repository's
 * collaborators and removes the person only where they are one, so only
 * real removals are logged and listed: the forge answers 204 to removing
 * someone who isn't there too. Each connection's own bot token does it (the
 * tracker never holds a site-admin token). Someone just deactivated has git
 * access nowhere, so it walks all connections, not only their office's.
 * Always succeeds: a forge failure is reported in the message so the
 * caller's change still stands.
 */
class RemoveFromForgeService
{
    /**
     * @return ServiceResult data: array{removed: list<string>, failed: list<string>} (forge organization names, then repository
     *                       full names; `{owner}/*` in failed when that organization's repositories could not be listed);
     *                       meta: array{complete: bool}, false whenever any removal failed
     */
    public function execute(?User $user): ServiceResult
    {
        $username = $user?->git_username;
        $removed = [];
        $failed = [];

        if ($username === null || $username === '') {
            return ServiceResult::success(['removed' => $removed, 'failed' => $failed], '', ['complete' => true]);
        }

        try {
            $connections = GitConnection::query()->orderBy('id')->get();
        } catch (Throwable) {
            report(new ForgejoException('Removing a member from the forge failed unexpectedly.'));

            return ServiceResult::success(['removed' => [], 'failed' => []], self::failureMessage($username), ['complete' => false]);
        }

        // Connections found unreachable (status 0) or unusable: what remains
        // of them counts as failed without another call, so one dead forge
        // costs one timeout and one report, not one each.
        $down = [];

        foreach ($connections as $connection) {
            try {
                ForgejoClient::for($connection)->removeOrganizationMember($connection->owner, $username);
                $removed[] = $connection->owner;
                Log::warning('Removed someone from a forge organization.', ['owner' => $connection->owner, 'login' => $username]);
            } catch (ForgejoException $e) {
                // Expected: the forge is down or refused (a 403 means the bot token lacks scope).
                // Log only where and what status, never the token or the exception.
                Log::warning('Forge member removal failed.', self::failureContext($connection, $e, ['owner' => $connection->owner]));
                $failed[] = $connection->owner;
                if ($e->status === 0) {
                    $down[$connection->id] = true;
                }
            } catch (Throwable) {
                // Unexpected, but the deactivation must stand. Never report the
                // throwable itself: it can carry the request and its token.
                report(new ForgejoException('Removing a member from the forge failed unexpectedly.'));
                $failed[] = $connection->owner;
                $down[$connection->id] = true;
            }
        }

        foreach ($connections as $connection) {
            $owner = $connection->owner;

            if (isset($down[$connection->id])) {
                $failed[] = "{$owner}/*";

                continue;
            }

            try {
                $client = ForgejoClient::for($connection);
                $repositories = self::repositories($client, $owner);
            } catch (ForgejoException $e) {
                Log::warning('Forge collaborator removal failed.', self::failureContext($connection, $e, ['owner' => $owner]));
                $failed[] = "{$owner}/*";

                continue;
            } catch (Throwable) {
                report(new ForgejoException('Removing a member from the forge failed unexpectedly.'));
                $failed[] = "{$owner}/*";

                continue;
            }

            foreach ($repositories as ['name' => $name, 'full_name' => $fullName]) {
                if (isset($down[$connection->id])) {
                    $failed[] = $fullName;

                    continue;
                }

                try {
                    if (self::removeCollaborator($client, $owner, $name, $username)) {
                        $removed[] = $fullName;
                        Log::warning("Took someone off a forge repository's collaborators.", ['owner' => $owner, 'repository' => $name, 'login' => $username]);
                    }
                } catch (ForgejoException $e) {
                    // As for the organizations: where and what status, never the token.
                    Log::warning('Forge collaborator removal failed.', self::failureContext($connection, $e, ['owner' => $owner, 'repository' => $name]));
                    $failed[] = $fullName;
                    if ($e->status === 0) {
                        $down[$connection->id] = true;
                    }
                } catch (Throwable) {
                    report(new ForgejoException('Removing a member from the forge failed unexpectedly.'));
                    $failed[] = $fullName;
                    $down[$connection->id] = true;
                }
            }
        }

        $message = $failed === []
            ? ($removed === [] ? '' : "{$username} was removed from the forge.")
            : self::failureMessage($username);

        return ServiceResult::success(['removed' => $removed, 'failed' => $failed], $message, ['complete' => $failed === []]);
    }

    /**
     * Every repository of the forge organization. One the forge no longer
     * has (404) has none.
     *
     * @return list<array{name: string, full_name: string}>
     *
     * @throws ForgejoException
     */
    private static function repositories(ForgejoClient $client, string $owner): array
    {
        try {
            $rows = $client->organizationRepositories($owner, PHP_INT_MAX);
        } catch (ForgejoException $e) {
            if ($e->status === 404) {
                return [];
            }

            throw $e;
        }

        $repositories = [];
        foreach ($rows as $row) {
            $name = ForgeShapes::string($row, 'name');
            if ($name !== null) {
                $repositories[] = ['name' => $name, 'full_name' => ForgeShapes::string($row, 'full_name') ?? "{$owner}/{$name}"];
            }
        }

        return $repositories;
    }

    /**
     * Whether the forge took them off: only where its list of collaborators
     * has them (any case). A repository the forge no longer has (404), or a
     * login it has no user for (422), had no access to take away: neither a
     * removal nor a failure.
     *
     * @throws ForgejoException
     */
    private static function removeCollaborator(ForgejoClient $client, string $owner, string $name, string $username): bool
    {
        try {
            $collaborators = array_map(mb_strtolower(...), $client->collaborators($owner, $name, PHP_INT_MAX));

            if (! in_array(mb_strtolower($username), $collaborators, true)) {
                return false;
            }

            $client->removeCollaborator($owner, $name, $username);
        } catch (ForgejoException $e) {
            if (in_array($e->status, [404, 422], true)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Where and what status; for an unreachable forge also its URL and the
     * cause with the token masked. Never the token or the exception.
     *
     * @param  array<string, string>  $where
     * @return array<string, int|string>
     */
    private static function failureContext(GitConnection $connection, ForgejoException $e, array $where): array
    {
        $context = [...$where, 'status' => $e->status];
        if ($e->status === 0) {
            $context['url'] = $connection->base_url;
            $context['cause'] = $e->causeMessage((string) $connection->token);
        }

        return $context;
    }

    private static function failureMessage(string $username): string
    {
        return "Couldn't remove {$username} from the forge; ask a super-admin to suspend them there today.";
    }
}
