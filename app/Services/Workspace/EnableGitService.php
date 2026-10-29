<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Support\ServiceResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Connects an organization to a forge organization. Nothing is saved unless
 * the forge answers and the token's user owns the forge organization.
 */
class EnableGitService
{
    /**
     * @param  array{base_url: string, owner: string, token: string}  $input
     */
    public function execute(Organization $organization, User $actor, array $input): ServiceResult
    {
        try {
            return $this->connect($organization, $actor, $input);
        } catch (Throwable $e) {
            // Never the original: a failed save's SQL carries the (encrypted)
            // token among its bindings, and its message would reach the log
            // and the response. The summary keeps the class and SQLSTATE.
            $safe = new RuntimeException("Couldn't turn git on: ".class_basename($e)
                .($e instanceof QueryException ? " (SQLSTATE {$e->getCode()})" : '').'.');
            report($safe);

            return ServiceResult::fromException($safe);
        }
    }

    /**
     * @param  array{base_url: string, owner: string, token: string}  $input
     */
    private function connect(Organization $organization, User $actor, array $input): ServiceResult
    {
        $baseUrl = GitConnection::normalizeBaseUrl($input['base_url']);
        $client = new ForgejoClient($baseUrl, $input['token']);

        try {
            $login = $client->currentUser()['login'];
        } catch (ForgejoException $e) {
            return $this->failed($e, $baseUrl, $input['token']);
        }

        try {
            $isOwner = $client->organizationPermissions($input['owner'], $login)['is_owner'];
        } catch (ForgejoException $e) {
            // The forge hides organizations (403/404) from a token that has no business in them.
            if ($e->status !== 403 && $e->status !== 404) {
                return $this->failed($e, $baseUrl, $input['token']);
            }
            $isOwner = false;
        }

        if (! $isOwner) {
            return ServiceResult::failure("{$login} doesn't own {$input['owner']} on the forge.", 422);
        }

        $connection = GitConnection::query()->firstOrNew(['organization_id' => $organization->id]);

        // A webhook belongs to one forge organization: another target means a new one.
        if ($connection->exists
            && ($connection->base_url !== $baseUrl || mb_strtolower($connection->owner) !== mb_strtolower($input['owner']))) {
            $connection->webhook_id = null;
            $connection->webhook_secret = null;
        }

        $connection->fill([
            'base_url' => $baseUrl,
            'owner' => $input['owner'],
            'token' => $input['token'],
            'enabled_by' => $actor->id,
        ])->save();

        return ServiceResult::success($organization->load('gitConnection'), 'Git is on.');
    }

    private function failed(ForgejoException $e, string $baseUrl, string $token): ServiceResult
    {
        if ($e->status === 0) {
            Log::warning('Forge unreachable.', ['url' => $baseUrl, 'cause' => $e->causeMessage($token)]);
        }

        return match (true) {
            $e->status === 0 || $e->status >= 500 => ServiceResult::failure("Couldn't reach the forge at {$baseUrl}.", 422),
            $e->status === 401 || $e->status === 403 => ServiceResult::failure('The forge refused that token.', 422),
            $e->status === 404 => ServiceResult::failure("{$baseUrl} doesn't look like a Forgejo forge.", 422),
            default => ServiceResult::failure("The forge answered {$e->status}. Try again shortly.", 422),
        };
    }
}
