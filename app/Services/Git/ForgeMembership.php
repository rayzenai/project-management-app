<?php

namespace App\Services\Git;

use App\Models\GitConnection;
use App\Models\Member;
use App\Models\User;
use App\Support\Git\GitAccess;
use App\Support\Git\GitUsernames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a forge organization's membership in step with the tracker, with the
 * connection's bot token. Only people the tracker knows are touched: logins
 * that are some user's git_username. The bots (`{owner}-bot`), `forge-admin`
 * and anyone the forge has that the tracker doesn't are never added, removed
 * or changed. Logins are compared lowercased, as the forge compares them.
 */
class ForgeMembership
{
    /**
     * One run removes at most this many people, or this share of the people
     * the tracker knows there when that is more; past it, it removes none.
     */
    public const REMOVAL_FLOOR = 5;

    public const REMOVAL_SHARE = 0.25;

    /**
     * The Owners team holds the organization's active office admins who
     * have a forge username, and no other person the tracker knows. An office
     * admin the forge has no account for yet (404) is skipped until they
     * first sign in there.
     *
     * Every change is logged at warning, which production keeps.
     *
     * @throws ForgejoException
     * @throws TooManyForgeRemovals when too many owners would go, unless $allowRemovals; the additions are made
     */
    public function syncOwners(GitConnection $connection, bool $allowRemovals = false): void
    {
        $client = ForgejoClient::for($connection);
        $team = $client->ownersTeamId($connection->owner);
        $owners = $client->teamMembers($team);

        $admins = User::query()
            ->whereIn('id', Member::query()->select('user_id')
                ->where('organization_id', $connection->organization_id)
                ->where('is_office_admin', true)
                ->where('is_active', true))
            ->whereNotNull('git_username')
            ->pluck('git_username')
            ->all();

        $isOwner = array_flip(array_map(mb_strtolower(...), $owners));
        $isAdmin = array_flip(array_map(mb_strtolower(...), $admins));
        $untouchable = $this->untouchable();

        foreach ($admins as $login) {
            if (isset($isOwner[mb_strtolower($login)]) || isset($untouchable[mb_strtolower($login)])) {
                continue;
            }

            try {
                $client->addTeamMember($team, $login);
                Log::warning('Made an office admin a forge owner.', ['owner' => $connection->owner, 'login' => $login]);
            } catch (ForgejoException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }

        $known = $this->known($owners);
        $leaving = array_values(array_filter($known, fn (array $owner): bool => ! isset($isAdmin[mb_strtolower($owner['login'])])));

        if (! $allowRemovals) {
            $this->holdBackMassRemoval($connection, 'owners', count($leaving), count($known));
        }

        foreach ($leaving as ['login' => $login]) {
            $client->removeTeamMember($team, $login);
            Log::warning('Took someone who is not an office admin off the forge owners.', ['owner' => $connection->owner, 'login' => $login]);
        }
    }

    /**
     * Takes out of the forge organization every person the tracker knows
     * whom GitAccess no longer admits to it: taken off a cross-organization
     * team, moved to another office, or deactivated. Their whole membership
     * goes, every team included.
     *
     * @throws ForgejoException
     * @throws TooManyForgeRemovals unless $allowRemovals
     */
    public function removeStrangers(GitConnection $connection, bool $allowRemovals = false): void
    {
        $client = ForgejoClient::for($connection);
        $known = $this->known($client->organizationMembers($connection->owner));
        $strangers = array_values(array_filter(
            $known,
            fn (array $member): bool => ! GitAccess::organizationsFor($member['user'])->contains('id', $connection->organization_id),
        ));

        if (! $allowRemovals) {
            $this->holdBackMassRemoval($connection, 'members', count($strangers), count($known));
        }

        foreach ($strangers as ['login' => $login]) {
            $client->removeOrganizationMember($connection->owner, $login);
            Log::warning('Took someone the tracker no longer lets in off the forge organization.', ['owner' => $connection->owner, 'login' => $login]);
        }
    }

    /**
     * A circuit breaker: a run that would remove more than REMOVAL_FLOOR
     * people, and more than REMOVAL_SHARE of those the tracker knows there,
     * removes none. `git:reconcile --allow-removals` lifts it for one run.
     *
     * @throws TooManyForgeRemovals
     */
    private function holdBackMassRemoval(GitConnection $connection, string $from, int $removing, int $known): void
    {
        if ($removing <= max(self::REMOVAL_FLOOR, $known * self::REMOVAL_SHARE)) {
            return;
        }

        Log::warning('Held back forge removals: too many at once.', [
            'owner' => $connection->owner,
            'from' => $from,
            'removing' => $removing,
            'known' => $known,
        ]);

        throw new TooManyForgeRemovals($removing, $known);
    }

    /**
     * The logins that are some tracker user's git_username, except the
     * untouchable ones, each with that user.
     *
     * @param  list<string>  $logins
     * @return list<array{login: string, user: User}> the login as the forge wrote it
     */
    private function known(array $logins): array
    {
        $untouchable = $this->untouchable();
        $logins = array_values(array_filter($logins, fn (string $login): bool => ! isset($untouchable[mb_strtolower($login)])));

        if ($logins === []) {
            return [];
        }

        $users = User::query()
            ->whereIn(DB::raw('lower(git_username)'), array_map(mb_strtolower(...), $logins))
            ->get()
            ->keyBy(fn (User $user): string => mb_strtolower((string) $user->git_username));

        $known = [];

        foreach ($logins as $login) {
            $user = $users->get(mb_strtolower($login));

            if ($user instanceof User) {
                $known[] = ['login' => $login, 'user' => $user];
            }
        }

        return $known;
    }

    /**
     * `forge-admin` and every connection's `{owner}-bot`, lowercased.
     *
     * @return array<string, true>
     */
    private function untouchable(): array
    {
        $names = [GitUsernames::FORGE_ADMIN => true];

        foreach (GitConnection::query()->pluck('owner') as $owner) {
            $names[mb_strtolower((string) $owner).'-bot'] = true;
        }

        return $names;
    }
}
