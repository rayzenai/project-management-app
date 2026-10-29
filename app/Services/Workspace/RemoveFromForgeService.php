<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes a deactivated or deleted person out of every connected forge
 * organization, with each connection's own bot token (the tracker never holds
 * a site-admin token). Someone just deactivated has git access nowhere, so it
 * walks all connections, not only their office's. Always succeeds: a forge
 * failure is reported in the message so the caller's change still stands.
 */
class RemoveFromForgeService
{
    /**
     * @return ServiceResult data: array{removed: list<string>, failed: list<string>} (forge organization names);
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

        $message = $failed === []
            ? ($removed === [] ? '' : "{$username} was removed from the forge.")
            : self::failureMessage($username);

        return ServiceResult::success(['removed' => $removed, 'failed' => $failed], $message, ['complete' => $failed === []]);
    }

    private static function failureMessage(string $username): string
    {
        return "Couldn't remove {$username} from the forge; ask a super-admin to suspend them there today.";
    }
}
