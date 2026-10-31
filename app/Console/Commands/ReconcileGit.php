<?php

namespace App\Console\Commands;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitWebhookDelivery;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Services\Git\ForgeMembership;
use App\Services\Git\ForgeShapes;
use App\Services\Git\RepositoryAccess;
use App\Services\Git\RepositoryAccessFailed;
use App\Services\Git\RepositorySync;
use App\Services\Git\TooManyForgeRemovals;
use App\Services\Git\UnlinksHeldBack;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The nightly repair. Forgejo never retries a webhook, and forge owners can
 * change what the tracker set up, so for each connection it puts back a
 * missing webhook, reconciles every linked repository (RepositorySync) and
 * writes its access (RepositoryAccess), unlinks the ones the forge no longer
 * has, syncs the Owners team, removes the people the tracker no longer lets
 * in (ForgeMembership) and prunes old deliveries.
 *
 * Breakers hold back mass removals and unlinks, which the tracker can't
 * undo; `--allow-removals` lifts them for one run, after a person checked.
 *
 * A failing step is logged without the token and the others still run; an
 * unreachable forge ends its connection's steps. One line per connection,
 * and a non-zero exit when any of them failed.
 */
class ReconcileGit extends Command
{
    /** Days a webhook delivery is kept. */
    private const DELIVERY_DAYS = 14;

    protected $signature = 'git:reconcile
        {--connection= : Only the git connection with this id}
        {--allow-removals : For this run only, lift the breakers that hold back mass removals and unlinks (check the departures first)}';

    protected $description = 'Put back missing webhooks, catch linked repositories up, and keep forge owners and members in step';

    public function handle(RepositorySync $sync, ForgeMembership $membership, RepositoryAccess $access): int
    {
        $only = $this->option('connection');
        $connections = GitConnection::query()
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get();

        if ($only !== null && $connections->isEmpty()) {
            $this->error("No git connection {$only}.");

            return self::FAILURE;
        }

        $allowRemovals = (bool) $this->option('allow-removals');

        if ($allowRemovals) {
            $this->warn('Removal breakers lifted for this run (--allow-removals).');
        }

        $failed = false;

        foreach ($connections as $connection) {
            $label = "{$connection->owner} at {$connection->base_url}";

            try {
                $failed = ! $this->reconcile($connection, $label, $sync, $membership, $access, $allowRemovals) || $failed;
            } catch (Throwable $e) {
                $this->error("{$label}: failed ({$this->reportSafely($connection, 'connection', $e)}).");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return bool false when any step failed
     */
    private function reconcile(GitConnection $connection, string $label, RepositorySync $sync, ForgeMembership $membership, RepositoryAccess $access, bool $allowRemovals): bool
    {
        $webhook = null;
        $reconciled = 0;
        $unlinked = 0;
        /** @var list<array{0: GitRepository, 1: string}> $gone */
        $gone = [];

        /** @var list<array{0: string, 1: Closure(): void}> $steps */
        $steps = [['webhook', function () use ($connection, &$webhook): void {
            $webhook = $this->restoreWebhook($connection);
        }]];

        $repositories = $connection->repositories()->orderBy('id')->get();

        foreach ($repositories as $repo) {
            $repo->setRelation('gitConnection', $connection);
            $steps[] = [$repo->full_name, function () use ($sync, $repo, &$reconciled, &$gone): void {
                $why = $sync->reconcile($repo);

                if ($why === null) {
                    $reconciled++;
                } else {
                    $gone[] = [$repo, $why];
                }
            }];
            // After its repository step, which may have found it gone.
            $steps[] = ["{$repo->full_name} access", function () use ($access, $repo, &$gone): void {
                if (! in_array($repo, array_column($gone, 0), true)) {
                    $access->sync($repo);
                }
            }];
        }

        // All or none, once every repository has been looked at.
        $steps[] = ['unlinks', function () use ($sync, $connection, $repositories, $allowRemovals, &$gone, &$unlinked): void {
            if ($gone !== []) {
                $unlinked = $sync->unlinkGone($connection, $gone, $repositories->count(), $allowRemovals);
            }
        }];
        $steps[] = ['owners', fn () => $membership->syncOwners($connection, $allowRemovals)];
        $steps[] = ['members', fn () => $membership->removeStrangers($connection, $allowRemovals)];

        $problems = [];

        foreach ($steps as [$step, $work]) {
            try {
                $work();
            } catch (ForgejoException $e) {
                $this->logFailure($connection, $step, $e);
                $problems[] = $step.': '.rtrim($e->getMessage(), '.');

                // Unreachable: every other step would only wait for it too.
                if ($e->status === 0) {
                    break;
                }
            } catch (TooManyForgeRemovals|UnlinksHeldBack $e) {
                // Logged where it was held back.
                $problems[] = $step.': '.rtrim($e->getMessage(), '.');
            } catch (Throwable $e) {
                // One broken repository must not hold up the membership steps.
                $problems[] = $step.': '.$this->reportSafely($connection, $step, $e);
            }
        }

        GitWebhookDelivery::query()
            ->where('git_connection_id', $connection->id)
            ->where('received_at', '<', now()->subDays(self::DELIVERY_DAYS))
            ->delete();

        $done = "reconciled {$reconciled} ".Str::plural('repository', $reconciled)
            .($unlinked > 0 ? ", unlinked {$unlinked}" : '')
            .($webhook !== null ? ", {$webhook}" : '');

        if ($problems !== []) {
            $this->error("{$label}: failed (".implode('; ', $problems)."); {$done}.");

            return false;
        }

        $this->line("{$label}: {$done}.");

        return true;
    }

    /**
     * Puts back the webhook when a forge owner deleted it (a new hook and
     * secret), or changed it: switched it off, pointed it elsewhere, narrowed
     * its branches or its content type (those settings again). A hook missing
     * an event the tracker needs, or whose deliveries came with a wrong
     * signature since the last run, is replaced by a new one with a new
     * secret, then deleted: Forgejo 15's hook edit can't switch the
     * workflow-run events on, and never writes a secret (editHook in
     * routers/api/v1/utils/hook.go), and the forge never shows one.
     *
     * @return string|null what it did, or null when the hook looked as it should
     */
    private function restoreWebhook(GitConnection $connection): ?string
    {
        $client = ForgejoClient::for($connection);
        $url = route('git.webhook', $connection);
        $id = $connection->webhook_id;
        $hook = $id === null ? null : $client->organizationHook($connection->owner, $id);

        $badSignature = Cache::pull(GitWebhookDelivery::badSignatureKey($connection->id)) !== null;

        if ($id !== null && $hook !== null && self::hookHasEvents($hook) && ! $badSignature) {
            if (self::hookIsIntact($hook, $url)) {
                return null;
            }

            $client->updateOrganizationHook($connection->owner, $id, $url, (string) $connection->webhook_secret);
            Log::warning("Put the forge webhook's settings back.", ['owner' => $connection->owner, 'url' => $connection->base_url]);

            return 'repaired the webhook';
        }

        $this->createWebhook($connection, $client, $url);

        if ($id === null || $hook === null) {
            Log::warning('Recreated the forge webhook.', ['owner' => $connection->owner, 'url' => $connection->base_url]);

            return 'recreated the webhook';
        }

        try {
            $client->deleteOrganizationHook($connection->owner, $id);
        } catch (ForgejoException $e) {
            // Signed with the old secret, its deliveries are refused here.
            Log::warning("Couldn't delete the forge webhook the tracker replaced; delete it on the forge.", ['owner' => $connection->owner, 'url' => $connection->base_url, 'hook' => $id, 'status' => $e->status]);
        }

        Log::warning($badSignature
            ? 'Replaced the forge webhook, whose deliveries came with a wrong signature.'
            : 'Replaced the forge webhook, which lacked events the tracker needs.', ['owner' => $connection->owner, 'url' => $connection->base_url]);

        return 'replaced the webhook';
    }

    /**
     * A new hook with a new secret, kept on the connection.
     */
    private function createWebhook(GitConnection $connection, ForgejoClient $client, string $url): void
    {
        $secret = Str::random(40);
        $id = $client->createOrganizationHook($connection->owner, $url, $secret);

        try {
            $connection->forceFill(['webhook_id' => $id, 'webhook_secret' => $secret])->save();
        } catch (Throwable $e) {
            // A hook whose secret is lost would only ever fail its signatures.
            rescue(fn () => $client->deleteOrganizationHook($connection->owner, $id), report: false);

            throw $e;
        }
    }

    /**
     * A branch filter of `*` or none sends every branch.
     *
     * @param  array<string, mixed>  $hook  api.Hook
     */
    private static function hookIsIntact(array $hook, string $url): bool
    {
        $config = ForgeShapes::map($hook, 'config');

        return ($hook['active'] ?? false) === true
            && ($config['url'] ?? null) === $url
            && ($config['content_type'] ?? null) === 'json'
            && in_array($hook['branch_filter'] ?? '', ['', '*'], true);
    }

    /**
     * Forgejo lists `pull_request` as itself plus its sub-events, and an
     * owner may add events: the hook only has to hold every event the
     * tracker subscribes to.
     *
     * @param  array<string, mixed>  $hook  api.Hook
     */
    private static function hookHasEvents(array $hook): bool
    {
        $events = is_array($hook['events'] ?? null) ? $hook['events'] : [];

        return array_diff(GitWebhookDelivery::EVENTS, $events) === [];
    }

    /**
     * Reports an unexpected failure by its class (and SQLSTATE), never the
     * original: a failed query's message carries its bindings, the encrypted
     * token or secret among them.
     *
     * @return string the summary
     */
    private function reportSafely(GitConnection $connection, string $step, Throwable $e): string
    {
        $summary = class_basename($e).($e instanceof QueryException ? " (SQLSTATE {$e->getCode()})" : '');
        report(new RuntimeException("The git reconcile of {$connection->owner} failed ({$step}): {$summary}."));

        return $summary;
    }

    private function logFailure(GitConnection $connection, string $step, ForgejoException $e): void
    {
        $context = [
            'owner' => $connection->owner,
            'step' => $step,
            ...($e instanceof RepositoryAccessFailed ? $e->context() : []),
            'status' => $e->status,
        ];

        if ($e->status === 0) {
            $context['url'] = $connection->base_url;
            $context['cause'] = $e->causeMessage((string) $connection->token);
        }

        Log::warning('A git reconcile step failed.', $context);
    }
}
