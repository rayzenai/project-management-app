<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes a deactivated or deleted person out of every connected forge
 * organization, then off the collaborators of every repository linked
 * through each connection, with each connection's own bot token (the tracker
 * never holds a site-admin token). Someone just deactivated has git access
 * nowhere, so it walks all connections, not only their office's. Always
 * succeeds: a forge failure is reported in the message so the caller's
 * change still stands.
 */
class RemoveFromForgeService
{
    /**
     * @return ServiceResult data: array{removed: list<string>, failed: list<string>} (forge organization names, then repository full names);
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
            $repositories = GitRepository::query()->orderBy('id')->get()->groupBy('git_connection_id');
        } catch (Throwable) {
            report(new ForgejoException('Removing a member from the forge failed unexpectedly.'));

            return ServiceResult::success(['removed' => [], 'failed' => []], self::failureMessage($username), ['complete' => false]);
        }

        foreach ($connections as $connection) {
            try {
                ForgejoClient::for($connection)->removeOrganizationMember($connection->owner, $username);
                $removed[] = $connection->owner;
                Log::warning('Removed someone from a forge organization.', ['owner' => $connection->owner, 'login' => $username]);
            } catch (ForgejoException $e) {
                // Expected: the forge is down or refused (a 403 means the bot token lacks scope).
                // Log only where and what status, never the token or the exception.
                $context = ['owner' => $connection->owner, 'status' => $e->status];
                if ($e->status === 0) {
                    $context['url'] = $connection->base_url;
                    $context['cause'] = $e->causeMessage((string) $connection->token);
                }
                Log::warning('Forge member removal failed.', $context);
                $failed[] = $connection->owner;
            } catch (Throwable) {
                // Unexpected, but the deactivation must stand. Never report the
                // throwable itself: it can carry the request and its token.
                report(new ForgejoException('Removing a member from the forge failed unexpectedly.'));
                $failed[] = $connection->owner;
            }
        }

        foreach ($connections as $connection) {
            foreach ($repositories->get($connection->id, []) as $repository) {
                try {
                    self::removeCollaborator($connection, $repository, $username);
                    $removed[] = $repository->full_name;
                    Log::warning("Took someone off a forge repository's collaborators.", ['owner' => $repository->owner, 'repository' => $repository->name, 'login' => $username]);
                } catch (ForgejoException $e) {
                    // As for the organizations: where and what status, never the token.
                    $context = ['owner' => $repository->owner, 'repository' => $repository->name, 'status' => $e->status];
                    if ($e->status === 0) {
                        $context['url'] = $connection->base_url;
                        $context['cause'] = $e->causeMessage((string) $connection->token);
                    }
                    Log::warning('Forge collaborator removal failed.', $context);
                    $failed[] = $repository->full_name;
                } catch (Throwable) {
                    report(new ForgejoException('Removing a member from the forge failed unexpectedly.'));
                    $failed[] = $repository->full_name;
                }
            }
        }

        $message = $failed === []
            ? ($removed === [] ? '' : "{$username} was removed from the forge.")
            : self::failureMessage($username);

        return ServiceResult::success(['removed' => $removed, 'failed' => $failed], $message, ['complete' => $failed === []]);
    }

    /**
     * A repository the forge no longer has (404), or a login it has no user
     * for (422), leaves no access to take away.
     *
     * @throws ForgejoException
     */
    private static function removeCollaborator(GitConnection $connection, GitRepository $repository, string $username): void
    {
        try {
            ForgejoClient::for($connection)->removeCollaborator($repository->owner, $repository->name, $username);
        } catch (ForgejoException $e) {
            if (! in_array($e->status, [404, 422], true)) {
                throw $e;
            }
        }
    }

    private static function failureMessage(string $username): string
    {
        return "Couldn't remove {$username} from the forge; ask a super-admin to suspend them there today.";
    }
}
