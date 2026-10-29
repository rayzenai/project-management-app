<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Organization;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Support\ServiceResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Connects an organization to a forge organization. Nothing is saved unless
 * the forge answers and the token's user owns the forge organization.
 * Re-pointing a connection (another forge, or another forge organization)
 * unlinks the repositories it had there.
 */
class EnableGitService
{
    public function __construct(private DisableGitService $disable) {}

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
        $repointed = $connection->exists
            && ($connection->base_url !== $baseUrl || mb_strtolower($connection->owner) !== mb_strtolower($input['owner']));
        $old = $repointed && $connection->webhook_id !== null
            ? ['client' => ForgejoClient::for($connection), 'owner' => $connection->owner, 'id' => $connection->webhook_id]
            : null;
        // The old forge's client, for handing its repositories' trackers back.
        $oldClient = $repointed ? ForgejoClient::for($connection) : null;
        /** @var list<GitRepository> $oldRepositories */
        $oldRepositories = [];

        if ($repointed) {
            $connection->webhook_id = null;
            $connection->webhook_secret = null;
        }

        $connection->fill([
            'base_url' => $baseUrl,
            'owner' => $input['owner'],
            'token' => $input['token'],
            'enabled_by' => $actor->id,
        ]);

        // The forge calls happen inside the transaction on purpose: the hook
        // URL needs the row's id, and nothing is saved unless the hook exists.
        $created = null;
        $unlinked = 0;
        DB::beginTransaction();
        try {
            $connection->save();

            // Forge ids are only unique on one forge: the old forge's
            // repositories would be confused with the new one's.
            if ($repointed) {
                foreach ($connection->repositories()->orderBy('id')->get() as $repository) {
                    $oldRepositories[] = $repository;
                    $repository->delete();
                    $unlinked++;
                }
            }

            if ($connection->webhook_id === null || $client->organizationHook($connection->owner, $connection->webhook_id) === null) {
                $secret = Str::random(40);
                $connection->webhook_id = $created = $client->createOrganizationHook($connection->owner, route('git.webhook', $connection), $secret);
                $connection->webhook_secret = $secret;
                $connection->save();
            }

            DB::commit();
        } catch (ForgejoException $e) {
            DB::rollBack();

            return $this->hookFailed($e, $baseUrl, $input['token']);
        } catch (Throwable $e) {
            DB::rollBack();
            if ($created !== null) {
                $this->dropOldHook($client, $connection->owner, $created);
            }

            throw $e;
        }

        // After the commit, so a re-point that rolled back leaves the old
        // forge's trackers alone; from the rows kept above, since they are gone.
        if ($oldClient !== null) {
            foreach ($oldRepositories as $repository) {
                if (! $this->disable->restoreIssueTracker($oldClient, $repository)) {
                    break;
                }
            }
        }

        if ($old !== null) {
            $this->dropOldHook($old['client'], $old['owner'], $old['id']);
        }

        $message = match ($unlinked) {
            0 => 'Git is on.',
            1 => 'Git is on. Unlinked 1 repository that was on the old forge.',
            default => "Git is on. Unlinked {$unlinked} repositories that were on the old forge.",
        };

        return ServiceResult::success($organization->load('gitConnection'), $message);
    }

    /**
     * The re-pointed connection's old hook is best effort: the old forge may be gone.
     */
    private function dropOldHook(ForgejoClient $client, string $owner, int $id): void
    {
        try {
            $client->deleteOrganizationHook($owner, $id);
        } catch (ForgejoException $e) {
            Log::warning('Could not delete a forge webhook.', ['status' => $e->status]);
        }
    }

    private function hookFailed(ForgejoException $e, string $baseUrl, string $token): ServiceResult
    {
        if ($e->status === 0) {
            Log::warning('Forge unreachable.', ['url' => $baseUrl, 'cause' => $e->causeMessage($token)]);

            return ServiceResult::failure("Couldn't reach the forge at {$baseUrl}.", 422);
        }

        return ServiceResult::failure("Couldn't create the forge webhook: the forge answered {$e->status}.", 422);
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
