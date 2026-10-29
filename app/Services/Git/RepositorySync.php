<?php

namespace App\Services\Git;

use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a linked repository in step with the forge through its API, for the
 * backfill when it is linked and for the nightly reconcile: what the
 * webhooks would have brought goes through GitIngest, so it links to tasks
 * the same way.
 */
class RepositorySync
{
    public const DEFAULT_DAYS = 90;

    /**
     * One run unlinks at most this many of a connection's repositories, or
     * this share of those linked when that is more; past it, it unlinks none.
     */
    public const UNLINK_FLOOR = 3;

    public const UNLINK_SHARE = 0.25;

    /** The most branches, and commits of one branch's history, the reconcile lists. */
    private const BRANCH_CAP = 200;

    private const HISTORY_CAP = 500;

    public function __construct(private readonly GitIngest $ingest) {}

    /**
     * Pull requests open or updated since $since, every branch, and the
     * default branch's commits since $since (90 days when null), each list
     * capped by the client (200 pull requests, 200 branches, 500 commits).
     *
     * Forgejo 15's commit list ignores `since`, so commits are filtered here
     * by their committer date: when they reached the branch. The newest one
     * moves the repository's last push forward.
     */
    public function catchUp(GitRepository $repo, ?CarbonInterface $since): void
    {
        $since ??= CarbonImmutable::now()->subDays(self::DEFAULT_DAYS);
        $client = ForgejoClient::for($repo->gitConnection);

        foreach ($client->pullRequests($repo->owner, $repo->name) as $pr) {
            if (($pr['state'] ?? null) === 'open' || self::updatedSince($pr, $since)) {
                $this->ingest->pullRequest($repo, ForgeShapes::pullRequest($pr));
            }
        }

        foreach ($client->branches($repo->owner, $repo->name) as $branch) {
            $name = ForgeShapes::string($branch, 'name');

            if ($name !== null) {
                $this->ingest->branch($repo, $name, ForgeShapes::string(ForgeShapes::map($branch, 'commit'), 'id'));
            }
        }

        $newest = null;

        foreach ($this->commits($client, $repo, $since) as $commit) {
            $at = $this->ingest->commit($repo, $commit)->committed_at;
            $newest = $newest === null || $at->greaterThan($newest) ? $at : $newest;
        }

        // "Last push" counts what this brings in too, and never moves back
        // (a webhook may have seen a later push meanwhile).
        if ($newest !== null) {
            GitRepository::query()
                ->whereKey($repo->getKey())
                ->where(fn (Builder $q) => $q->whereNull('last_pushed_at')->orWhere('last_pushed_at', '<', $newest))
                ->update(['last_pushed_at' => $newest]);
        }
    }

    /**
     * The nightly pass over one linked repository, looked up by its forge id
     * since Forgejo sends no rename or transfer events. One the forge no
     * longer has (404), or that moved to another owner, is left as it is and
     * the reason comes back, for unlinkGone to weigh with the others.
     * Otherwise its names, URLs and default branch are refreshed, the link
     * back is set again (a refusal is only logged, as on link), it catches up
     * from a day before the last pass (90 days on the first), and commits a
     * force-push left behind are marked unreachable.
     *
     * @return string|null null when reconciled, otherwise why it should be unlinked
     *
     * @throws ForgejoException when the forge fails
     */
    public function reconcile(GitRepository $repo): ?string
    {
        $startedAt = CarbonImmutable::now();
        $owner = $repo->gitConnection->owner;
        $client = ForgejoClient::for($repo->gitConnection);

        try {
            $fields = ForgeShapes::repository($client->repositoryById($repo->forge_id));
        } catch (ForgejoException $e) {
            if ($e->status !== 404) {
                throw $e;
            }

            return 'gone from the forge';
        }

        if ($fields['owner'] !== null && mb_strtolower($fields['owner']) !== mb_strtolower($owner)) {
            return 'moved to another owner';
        }

        $repo->fill(array_filter($fields, fn (?string $value): bool => $value !== null))->save();

        $this->linkBack($repo);

        $since = $repo->reconciled_at?->subDay() ?? $startedAt->subDays(self::DEFAULT_DAYS);
        $this->catchUp($repo, $since);
        $this->markUnreachable($client, $repo, $since, $startedAt);

        $repo->forceFill(['reconciled_at' => $startedAt])->save();

        return null;
    }

    /**
     * Unlinks the repositories reconcile found gone, all or none. An unlink
     * loses the repository's history in the tracker, and a bot taken off the
     * Owners, or an organization deleted or renamed, makes every repository
     * look gone. So it first asks once whether the bot still owns the
     * connection's forge organization, and then, unless $allowRemovals,
     * refuses more than UNLINK_FLOOR, or UNLINK_SHARE of the $linked ones
     * when that is more.
     *
     * @param  list<array{0: GitRepository, 1: string}>  $gone  each with why
     * @return int how many it unlinked
     *
     * @throws UnlinksHeldBack
     * @throws ForgejoException when the forge fails
     */
    public function unlinkGone(GitConnection $connection, array $gone, int $linked, bool $allowRemovals = false): int
    {
        if ($gone === []) {
            return 0;
        }

        $this->confirmBotOwns($connection, count($gone));

        if (! $allowRemovals && count($gone) > max(self::UNLINK_FLOOR, $linked * self::UNLINK_SHARE)) {
            Log::warning('Held back repository unlinks: too many at once.', [
                'owner' => $connection->owner,
                'unlinking' => count($gone),
                'linked' => $linked,
            ]);

            throw new UnlinksHeldBack('Held back: it would unlink '.count($gone)." of the {$linked} linked repositories.");
        }

        foreach ($gone as [$repo, $why]) {
            $repo->delete();

            Log::warning('Unlinked a repository the forge organization no longer has.', ['repository' => $repo->full_name, 'why' => $why]);
        }

        return count($gone);
    }

    /**
     * Points the forge's external tracker at the go route, so `TP-12`
     * written on the forge opens the task here. A refusal (the bot token
     * lacking `write:repository`, say) is logged without the token and
     * answered with false; the link stands either way.
     */
    public function linkBack(GitRepository $repo): bool
    {
        $connection = $repo->gitConnection;

        try {
            ForgejoClient::for($connection)->setExternalTracker($repo->owner, $repo->name, self::trackerFormat());

            return true;
        } catch (ForgejoException $e) {
            Log::warning("Couldn't set the forge link back.", array_filter([
                'repository' => $repo->full_name,
                'status' => $e->status,
                'cause' => $e->causeMessage($connection->token),
            ], fn (int|string $value): bool => $value !== ''));

            return false;
        }
    }

    /**
     * Forgejo fills {user} with the repository's owner (the forge
     * organization) and {index} with the whole reference (`TP-12`).
     */
    public static function trackerFormat(): string
    {
        return rtrim((string) config('app.url'), '/').'/workspace/go/{user}/{index}';
    }

    /**
     * The default branch's commits since $since in GitIngest's shape. An
     * empty repository (409) has none.
     *
     * @return list<array{sha: string, message: string, author_name: string, author_username: ?string, url: string, committed_at: string}>
     */
    private function commits(ForgejoClient $client, GitRepository $repo, CarbonInterface $since): array
    {
        try {
            $listed = $client->commits($repo->owner, $repo->name, $repo->default_branch, $since);
        } catch (ForgejoException $e) {
            if ($e->status === 409) {
                return [];
            }

            throw $e;
        }

        $commits = [];

        foreach ($listed as $commit) {
            $git = ForgeShapes::map($commit, 'commit');
            $author = ForgeShapes::map($git, 'author');
            $committed = ForgeShapes::commitDate($commit);

            if ($committed === null || CarbonImmutable::parse($committed)->lessThan($since)) {
                continue;
            }

            // Stored with the author's time, as webhooks send it (repository.CommitToPushCommit).
            $commits[] = [
                'sha' => (string) ForgeShapes::string($commit, 'sha'),
                'message' => (string) ($git['message'] ?? ''),
                'author_name' => (string) ForgeShapes::string($author, 'name'),
                'author_username' => ForgeShapes::string(ForgeShapes::map($commit, 'author'), 'login'),
                'url' => (string) ForgeShapes::string($commit, 'html_url'),
                'committed_at' => ForgeShapes::string($author, 'date') ?? $committed,
            ];
        }

        return $commits;
    }

    /**
     * A force-push leaves commits no branch reaches any more. Forgejo can't
     * say which branches hold a commit, so every branch's history is listed
     * back to $since. A stored commit from $since on that no history shows is
     * marked unreachable (never deleted), and an unreachable one a history
     * shows again is cleared. When the branches, or a history still inside
     * the window, run into the caps, a missing commit proves nothing and
     * nothing is marked. A commit pushed while this runs can be marked; the
     * next night clears it.
     *
     * The same branch list prunes stored branches the forge no longer has
     * (their delete webhook was missed), unless it ran into its cap, or the
     * branch changed since $now (a webhook made it while this ran).
     *
     * The window has a gap: commits are stored with their author date (as
     * webhooks send it), while histories are paged by committer date. A
     * commit authored before $since but pushed after it (a rebase, a
     * cherry-pick) is never judged, and one whose committer date is older
     * than its author date (a skewed clock) can sit past the page where the
     * listing stopped and be marked although a branch holds it.
     */
    private function markUnreachable(ForgejoClient $client, GitRepository $repo, CarbonInterface $since, CarbonInterface $now): void
    {
        $branches = $client->branches($repo->owner, $repo->name, self::BRANCH_CAP);
        $complete = count($branches) < self::BRANCH_CAP;
        $seen = [];
        $listed = [];

        foreach ($branches as $branch) {
            $name = ForgeShapes::string($branch, 'name');

            if ($name === null) {
                continue;
            }

            $listed[$name] = true;

            try {
                $history = $client->commits($repo->owner, $repo->name, $name, $since, self::HISTORY_CAP);
            } catch (ForgejoException $e) {
                // The branch went (404) or holds nothing (409) since it was listed.
                if ($e->status !== 404 && $e->status !== 409) {
                    throw $e;
                }

                $history = [];
            }

            $reachedBack = count($history) < self::HISTORY_CAP;

            foreach ($history as $commit) {
                $sha = ForgeShapes::string($commit, 'sha');
                $date = ForgeShapes::commitDate($commit);

                if ($sha !== null) {
                    $seen[$sha] = true;
                }

                if ($date !== null && CarbonImmutable::parse($date)->lessThan($since)) {
                    $reachedBack = true;
                }
            }

            $complete = $complete && $reachedBack;
        }

        $clear = [];
        $mark = [];

        // The unreachable ones, and the reachable ones from $since on.
        $stored = $repo->commits()
            ->where(fn (Builder $q) => $q->whereNotNull('unreachable_at')->orWhere('committed_at', '>=', $since))
            ->get(['id', 'sha', 'unreachable_at']);

        foreach ($stored as $commit) {
            if (isset($seen[$commit->sha])) {
                if ($commit->unreachable_at !== null) {
                    $clear[] = $commit->id;
                }
            } elseif ($complete && $commit->unreachable_at === null) {
                $mark[] = $commit->id;
            }
        }

        foreach (array_chunk($clear, 1000) as $ids) {
            GitCommit::query()->whereKey($ids)->update(['unreachable_at' => null]);
        }

        foreach (array_chunk($mark, 1000) as $ids) {
            GitCommit::query()->whereKey($ids)->update(['unreachable_at' => $now]);
        }

        if (count($branches) < self::BRANCH_CAP) {
            // Timestamps are stored to the second: anything from $now's second on may be newer.
            foreach ($repo->branches()->where('updated_at', '<', $now->startOfSecond())->pluck('name') as $name) {
                if (! isset($listed[$name])) {
                    $this->ingest->deleteBranch($repo, $name);
                }
            }
        }
    }

    /**
     * @throws UnlinksHeldBack when the bot doesn't own the organization by that name
     * @throws ForgejoException when the forge fails
     */
    private function confirmBotOwns(GitConnection $connection, int $unlinking): void
    {
        $client = ForgejoClient::for($connection);
        $login = $client->currentUser()['login'];

        try {
            $owns = $client->organizationPermissions($connection->owner, $login)['is_owner'];
        } catch (ForgejoException $e) {
            // The forge hides (403) or no longer has (404) the organization.
            if ($e->status !== 403 && $e->status !== 404) {
                throw $e;
            }

            $owns = false;
        }

        if ($owns) {
            return;
        }

        $why = "the bot no longer owns {$connection->owner}; check the Organizations page";
        Log::warning("Held back repository unlinks: {$why}.", ['owner' => $connection->owner, 'login' => $login, 'unlinking' => $unlinking]);

        throw new UnlinksHeldBack("Held back: {$why}.");
    }

    /**
     * @param  array<string, mixed>  $pr  api.PullRequest
     */
    private static function updatedSince(array $pr, CarbonInterface $since): bool
    {
        $updated = ForgeShapes::string($pr, 'updated_at');

        return $updated !== null && ! CarbonImmutable::parse($updated)->lessThan($since);
    }
}
