<?php

namespace App\Services\Git;

use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a linked repository's forge collaborators in step with the tracker,
 * with the connection's bot token: the project's maintainers
 * (WorkspaceAccess::repositoryMaintainerIds) as `admin`, its effective
 * grants at their permission. Only people the tracker knows are taken off;
 * the bots, `forge-admin` and logins no user has are left alone, as
 * ForgeMembership leaves them. The forge organization's Owners and site
 * admins reach every repository without being collaborators.
 */
class RepositoryAccess
{
    public const ADMIN = 'admin';

    /** When someone is wanted twice, the higher permission wins. */
    private const RANK = [GitRepositoryGrant::READ => 1, GitRepositoryGrant::WRITE => 2, self::ADMIN => 3];

    /**
     * Who should be a collaborator, keyed by forge username as stored in
     * users.git_username: the maintainers `admin`, the effective grants at
     * their permission. `waiting` are the member ids among them with no
     * login or no forge username yet (they never signed in to the forge).
     * The bots and `forge-admin` are never wanted, whoever carries the name.
     *
     * @return array{logins: array<string, string>, waiting: list<int>}
     */
    public function wanted(GitRepository $repository): array
    {
        // Read afresh: a sync usually follows a change to the team or program.
        $project = Project::query()->findOrFail($repository->project_id);
        $permissions = array_fill_keys(WorkspaceAccess::repositoryMaintainerIds($project), self::ADMIN);

        foreach ($repository->grants()->effective()->get(['member_id', 'permission']) as $grant) {
            $permissions[$grant->member_id] = self::higher($permissions[$grant->member_id] ?? null, $grant->permission);
        }

        $untouchable = ForgeMembership::untouchableLogins();
        $logins = [];
        $waiting = [];

        foreach (Member::query()->with('user')->whereKey(array_keys($permissions))->orderBy('id')->get() as $member) {
            $login = (string) $member->user?->git_username;

            if ($login === '') {
                $waiting[] = $member->id;

                continue;
            }

            if (isset($untouchable[mb_strtolower($login)])) {
                continue;
            }

            $logins[$login] = self::higher($logins[$login] ?? null, $permissions[$member->id]);
        }

        return ['logins' => $logins, 'waiting' => $waiting];
    }

    /**
     * Writes the repository's access to the forge. First it takes off every
     * collaborator the tracker knows who is not wanted: that needs no grant
     * to have worked, and a failure stops the run before anyone gains
     * access. Then it PUTs every wanted login (a 422, no such forge user,
     * leaves them waiting), and last puts the main branch's rule
     * (MainBranch) back, whatever anyone changed or removed on the forge;
     * `protects_main` says whether it is on (not while the repository has
     * no such branch yet).
     *
     * A PUT the forge refuses otherwise (an account it disabled answers 500,
     * a blocked one 403) holds up nobody else: the others and the rule are
     * still written, then the run fails with the first refusal. An
     * unreachable forge fails it at once.
     *
     * New collaborators and removals are logged at warning, which production
     * keeps. A PUT for someone already there restates their permission (the
     * forge's list doesn't say what it was) and is not logged, or every run
     * would log everyone.
     *
     * @return array{granted: list<string>, removed: list<string>, waiting: list<string>} `granted` the logins PUT, `waiting` logins the forge has no user for and the names of people with no forge username
     *
     * @throws ForgejoException listing the collaborators
     * @throws RepositoryAccessFailed any later call, but a 422 on a PUT
     */
    public function sync(GitRepository $repository): array
    {
        $client = ForgejoClient::for($repository->gitConnection);
        $owner = $repository->owner;
        $name = $repository->name;
        $wanted = $this->wanted($repository);
        $collaborators = $client->collaborators($owner, $name);
        $isWanted = array_flip(array_map(fn (int|string $login): string => mb_strtolower((string) $login), array_keys($wanted['logins'])));
        $removed = [];

        foreach (ForgeMembership::known($collaborators) as ['login' => $login]) {
            if (isset($isWanted[mb_strtolower($login)])) {
                continue;
            }

            try {
                $client->removeCollaborator($owner, $name, $login);
            } catch (ForgejoException $e) {
                throw new RepositoryAccessFailed($e, 'delete', $login);
            }

            $removed[] = $login;
            Log::warning("Took someone off a forge repository's collaborators.", ['owner' => $owner, 'repository' => $name, 'login' => $login, 'permission' => 'none']);
        }

        $isCollaborator = array_flip(array_map(mb_strtolower(...), $collaborators));
        $granted = [];
        $waiting = [];
        $refused = null;

        foreach ($wanted['logins'] as $login => $permission) {
            $login = (string) $login;

            try {
                $client->setCollaborator($owner, $name, $login, $permission);
            } catch (ForgejoException $e) {
                if ($e->status === 422) {
                    $waiting[] = $login;

                    continue;
                }

                $failure = new RepositoryAccessFailed($e, 'put', $login);

                // Unreachable: every other call would only wait for it too.
                if ($e->status === 0) {
                    throw $failure;
                }

                $refused ??= $failure;

                continue;
            }

            $granted[] = $login;

            if (! isset($isCollaborator[mb_strtolower($login)])) {
                Log::warning('Gave someone access to a forge repository.', ['owner' => $owner, 'repository' => $name, 'login' => $login, 'permission' => $permission]);
            }
        }

        try {
            $protected = MainBranch::ensure($client, $owner, $name, $repository->protected_branch);
        } catch (ForgejoException $e) {
            $protected = false;
            $refused ??= new RepositoryAccessFailed($e, 'patch');
        }

        if ($repository->protects_main !== $protected) {
            $repository->forceFill(['protects_main' => $protected])->save();
        }

        if ($refused !== null) {
            throw $refused;
        }

        $repository->forceFill(['access_synced_at' => now()])->save();

        if ($wanted['waiting'] !== []) {
            $names = Member::query()->whereKey($wanted['waiting'])->orderBy('name')->get()->map(fn (Member $member): string => $member->name);
            $waiting = [...$waiting, ...$names->values()->all()];
        }

        return ['granted' => $granted, 'removed' => $removed, 'waiting' => $waiting];
    }

    /**
     * Turns the people a repository made on the forge already has into
     * grants, so linking it never silently takes their access away: each
     * collaborator the tracker knows whom an organizational admin could
     * grant (an active person of the project's organization or on its team,
     * not a maintainer: the administrator's side of
     * WorkspaceAccess::canGrantRepositoryAccessTo, as
     * RepositoryAccessCandidatesQuery lists it; keep them in step) and who
     * has no grant on it yet. The forge's `admin`, `owner` and `write`
     * become `write`, `read` stays `read`; `none`, or a reply without a
     * permission, gives nothing.
     *
     * @return int how many grants it made
     *
     * @throws ForgejoException
     */
    public function importExisting(GitRepository $repository, ?User $actor): int
    {
        $client = ForgejoClient::for($repository->gitConnection);
        $project = Project::query()->findOrFail($repository->project_id);
        $loginOf = [];

        foreach (ForgeMembership::known($client->collaborators($repository->owner, $repository->name)) as ['login' => $login, 'user' => $user]) {
            $loginOf[$user->id] = $login;
        }

        if ($loginOf === []) {
            return 0;
        }

        $members = Member::query()
            ->active()
            ->whereNotNull('organization_id')
            ->whereIn('user_id', array_keys($loginOf))
            ->whereKeyNot(WorkspaceAccess::repositoryMaintainerIds($project))
            ->whereDoesntHave('repositoryGrants', fn (Builder $grant): Builder => $grant->where('git_repository_id', $repository->getKey()))
            ->where(fn (Builder $member): Builder => $member
                ->where('organization_id', $project->organization_id)
                ->orWhereHas('teams.projects', fn (Builder $p): Builder => $p->where('projects.id', $project->getKey())))
            ->orderBy('id')
            ->get();

        $imported = 0;

        foreach ($members as $member) {
            $permission = match ($client->collaboratorPermission($repository->owner, $repository->name, $loginOf[(int) $member->user_id])) {
                'admin', 'owner', 'write' => GitRepositoryGrant::WRITE,
                'read' => GitRepositoryGrant::READ,
                default => null,
            };

            if ($permission === null) {
                continue;
            }

            GitRepositoryGrant::query()->create([
                'git_repository_id' => $repository->id,
                'member_id' => $member->id,
                'permission' => $permission,
                'granted_by' => $actor?->id,
            ]);
            $imported++;
        }

        return $imported;
    }

    /**
     * Logs a failed sync at warning without the token: the call and login
     * that failed when it says, the status, and for an unreachable forge its
     * address and the cause with the token masked.
     */
    public static function logFailure(GitRepository $repository, ForgejoException $e): void
    {
        $context = [
            'owner' => $repository->owner,
            'repository' => $repository->name,
            ...($e instanceof RepositoryAccessFailed ? $e->context() : []),
            'status' => $e->status,
        ];

        if ($e->status === 0) {
            $connection = $repository->gitConnection;
            $context['url'] = $connection->base_url;
            $context['cause'] = $e->causeMessage((string) $connection->token);
        }

        Log::warning("Couldn't sync a forge repository's access.", $context);
    }

    private static function higher(?string $current, string $permission): string
    {
        return $current !== null && (self::RANK[$current] ?? 0) >= (self::RANK[$permission] ?? 0) ? $current : $permission;
    }
}
