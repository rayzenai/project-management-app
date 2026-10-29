<?php

namespace App\Jobs;

use App\Models\GitRepository;
use App\Models\GitWebhookDelivery;
use App\Services\Git\ForgeShapes;
use App\Services\Git\GitIngest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Applies one stored webhook delivery through GitIngest, once: the delivery
 * row is locked and marked processed in the same transaction as the work.
 * Deliveries for repositories no project links are marked processed and
 * dropped. Payload shapes are Forgejo 15's (modules/structs/hook.go).
 *
 * Forgejo sends a `repository` event only on create and delete. A rename
 * reaches the tracker only as the new names in later deliveries, so every
 * delivery refreshes the repository's names and URLs. A transfer to another
 * owner sends nothing to the old organization's webhook; the reconcile
 * catches it.
 */
class ProcessGitDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public GitWebhookDelivery $delivery) {}

    public function handle(GitIngest $ingest): void
    {
        try {
            DB::transaction(function () use ($ingest): void {
                $delivery = GitWebhookDelivery::query()->lockForUpdate()->find($this->delivery->id);

                if ($delivery === null || $delivery->processed_at !== null) {
                    return;
                }

                $this->apply($ingest, $delivery);

                $delivery->forceFill(['processed_at' => now(), 'error' => null])->save();
            });
        } catch (Throwable $e) {
            // A query's message carries its bindings (commit text, titles):
            // keep only the class and SQLSTATE, on the row and in the log.
            $error = $e instanceof QueryException
                ? $e::class.' (SQLSTATE '.$e->getCode().')'
                : Str::limit($e::class.': '.$e->getMessage(), 2000);

            GitWebhookDelivery::query()->whereKey($this->delivery->id)->update(['error' => $error]);

            throw $e instanceof QueryException ? new RuntimeException($error) : $e;
        }
    }

    private function apply(GitIngest $ingest, GitWebhookDelivery $delivery): void
    {
        $payload = $delivery->payload;
        $repository = ForgeShapes::map($payload, 'repository');

        $repo = GitRepository::query()
            ->where('git_connection_id', $delivery->git_connection_id)
            ->where('forge_id', (int) ($repository['id'] ?? 0))
            ->first();

        if ($repo === null) {
            return;
        }

        if ($delivery->event === 'repository') {
            if (($payload['action'] ?? null) === 'deleted') {
                $repo->delete();

                // Only once the delivery has committed: a rolled-back retry must not log it twice.
                DB::afterCommit(fn () => Log::warning('Unlinked a repository deleted on the forge.', ['repository' => $repo->full_name]));
            }

            return;
        }

        $this->refresh($repo, $repository);

        match ($delivery->event) {
            'push' => $this->push($ingest, $repo, $payload, $delivery),
            'create' => $this->createBranch($ingest, $repo, $payload),
            'delete' => $this->deleteBranch($ingest, $repo, $payload),
            'pull_request' => $ingest->pullRequest($repo, ForgeShapes::pullRequest(ForgeShapes::map($payload, 'pull_request'))),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $repository  the delivery's api.Repository
     */
    private function refresh(GitRepository $repo, array $repository): void
    {
        $repo->fill(array_filter(ForgeShapes::repository($repository), fn (?string $value): bool => $value !== null))->save();
    }

    /**
     * Every commit the payload lists (Forgejo caps the list; the reconcile
     * fills the rest), then the branch head.
     *
     * @param  array<string, mixed>  $payload  api.PushPayload
     */
    private function push(GitIngest $ingest, GitRepository $repo, array $payload, GitWebhookDelivery $delivery): void
    {
        foreach ((array) ($payload['commits'] ?? []) as $commit) {
            $commit = (array) $commit;
            $author = ForgeShapes::map($commit, 'author');

            $ingest->commit($repo, [
                'sha' => (string) ForgeShapes::string($commit, 'id'),
                'message' => (string) ($commit['message'] ?? ''),
                'author_name' => (string) ForgeShapes::string($author, 'name'),
                'author_username' => ForgeShapes::string($author, 'username'),
                'url' => (string) ForgeShapes::string($commit, 'url'),
                'committed_at' => (string) ForgeShapes::string($commit, 'timestamp'),
            ]);
        }

        $ref = (string) ForgeShapes::string($payload, 'ref');

        if (str_starts_with($ref, 'refs/heads/')) {
            $ingest->branch($repo, Str::after($ref, 'refs/heads/'), ForgeShapes::string($payload, 'after'));
        }

        // A retried older delivery never moves the time back.
        if ($repo->last_pushed_at === null || $delivery->received_at->greaterThan($repo->last_pushed_at)) {
            $repo->forceFill(['last_pushed_at' => $delivery->received_at])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload  api.CreatePayload; `ref` is the full ref name
     */
    private function createBranch(GitIngest $ingest, GitRepository $repo, array $payload): void
    {
        if (($payload['ref_type'] ?? null) === 'branch') {
            $ingest->branch($repo, self::branchName($payload), ForgeShapes::string($payload, 'sha'));
        }
    }

    /**
     * @param  array<string, mixed>  $payload  api.DeletePayload
     */
    private function deleteBranch(GitIngest $ingest, GitRepository $repo, array $payload): void
    {
        if (($payload['ref_type'] ?? null) === 'branch') {
            $ingest->deleteBranch($repo, self::branchName($payload));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function branchName(array $payload): string
    {
        return Str::after((string) ForgeShapes::string($payload, 'ref'), 'refs/heads/');
    }
}
