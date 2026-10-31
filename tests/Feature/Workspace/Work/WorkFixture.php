<?php

namespace Tests\Feature\Workspace\Work;

use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;

/**
 * The work reports' test world, shared by the web and API tests.
 *
 * SDOC (with git): Asha Admin (organizational admin); the ED; under the ED
 * Dipak Director and Pema Peer; under the director Suman and Anil. Nagarik
 * is a project whose team is the director, Suman and Anil; Secret is a
 * private project only Suman is on. Each has a repository. MoF: Mohan
 * Admin. The boss is a super-admin with no organization.
 */
final class WorkFixture
{
    public Organization $sdoc;

    public Organization $mof;

    public User $boss;

    public User $admin;

    public User $ed;

    public User $director;

    public User $peer;

    public User $suman;

    public User $anil;

    public User $mofAdmin;

    public Project $nagarik;

    public Project $secret;

    public GitRepository $nagarikRepo;

    public GitRepository $secretRepo;

    public static function make(): self
    {
        config(['project-management.super_admins' => ['boss@example.com']]);

        $world = new self;
        $world->sdoc = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
        $world->mof = Organization::factory()->create(['name' => 'MoF', 'slug' => 'mof']);
        $git = GitConnection::query()->create([
            'organization_id' => $world->sdoc->id,
            'base_url' => 'https://git.example',
            'owner' => 'sdoc',
            'token' => 'bot-token',
            'webhook_id' => 1,
            'webhook_secret' => 'secret',
        ]);

        $world->boss = User::factory()->create(['email' => 'boss@example.com', 'name' => 'Boss']);
        $world->admin = self::person($world->sdoc, 'Asha Admin', admin: true);
        $world->ed = self::person($world->sdoc, 'Ek ED');
        $world->director = self::person($world->sdoc, 'Dipak Director', boss: $world->ed);
        $world->peer = self::person($world->sdoc, 'Pema Peer', boss: $world->ed);
        $world->suman = self::person($world->sdoc, 'Suman Karki', boss: $world->director, gitUsername: 'suman');
        $world->anil = self::person($world->sdoc, 'Anil Gurung', boss: $world->director, gitUsername: 'anil');
        $world->mofAdmin = self::person($world->mof, 'Mohan Admin', admin: true);

        $world->nagarik = Project::factory()->create(['organization_id' => $world->sdoc->id, 'title' => 'Nagarik App', 'slug' => 'nagarik', 'key' => 'NAGARIK']);
        $world->secret = Project::factory()->create(['organization_id' => $world->sdoc->id, 'title' => 'Secret', 'slug' => 'secret', 'key' => 'SEC']);
        $team = $world->nagarik->ensureTeam();
        $team->members()->attach(self::member($world->director)->id, ['role' => 'leader']);
        $team->members()->attach(self::member($world->suman)->id, ['role' => 'member']);
        $team->members()->attach(self::member($world->anil)->id, ['role' => 'member']);
        $world->secret->ensureTeam()->members()->attach(self::member($world->suman)->id, ['role' => 'leader']);

        $world->nagarikRepo = self::repository($git, $world->nagarik, 'nagarik', 1);
        $world->secretRepo = self::repository($git, $world->secret, 'secret-api', 2);

        return $world;
    }

    public static function person(Organization $organization, string $name, bool $admin = false, ?User $boss = null, ?string $gitUsername = null): User
    {
        $user = User::factory()->create(['name' => $name, 'git_username' => $gitUsername]);
        Member::factory()->linkedTo($user)->create([
            'organization_id' => $organization->id,
            'is_office_admin' => $admin,
            'reports_to_id' => $boss === null ? null : self::member($boss)->id,
        ]);

        return $user;
    }

    public static function member(User $user): Member
    {
        return Member::query()->where('user_id', $user->id)->firstOrFail();
    }

    public static function task(Project $project, string $title, string $status = 'not_started', ?string $deadline = null): Task
    {
        return Task::factory()->create(['project_id' => $project->id, 'title' => $title, 'status' => $status, 'deadline_at' => $deadline]);
    }

    /**
     * A status change as the activity log records it, by `$actor` at `$at`.
     */
    public static function status(Task $task, User $actor, string $to, string $at): void
    {
        $activity = new ProjectActivity;
        $activity->forceFill([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
            'action' => ProjectActivity::ACTION_STATUS_CHANGED,
            'changes' => ['from' => $task->status, 'to' => $to],
            'description' => "Status: {$task->status} → {$to}",
            'is_public' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();
    }

    public static function assign(Task $task, User $user): void
    {
        ProjectAssignment::query()->create(['task_id' => $task->id, 'member_id' => self::member($user)->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function commit(GitRepository $repo, User $author, string $sha, string $committedAt, array $attributes = []): GitCommit
    {
        $commit = new GitCommit;
        $commit->forceFill($attributes + [
            'git_repository_id' => $repo->id,
            'user_id' => $author->id,
            'sha' => $sha,
            'message' => 'Change things',
            'author_name' => $author->name,
            'author_username' => $author->git_username,
            'url' => "{$repo->html_url}/commit/{$sha}",
            'committed_at' => $committedAt,
            'is_merge' => false,
            'code_added' => 10,
            'code_removed' => 2,
            'comment_added' => 3,
            'comment_removed' => 1,
            'stats_status' => 'ok',
        ])->save();

        return $commit;
    }

    public static function pullRequest(GitRepository $repo, int $number, string $author, ?string $mergedAt): GitPullRequest
    {
        return GitPullRequest::query()->create([
            'git_repository_id' => $repo->id,
            'number' => $number,
            'title' => "Change {$number}",
            'state' => $mergedAt === null ? GitPullRequest::STATE_OPEN : GitPullRequest::STATE_MERGED,
            'url' => "{$repo->html_url}/pulls/{$number}",
            'head_branch' => "change-{$number}",
            'author_username' => $author,
            'opened_at' => '2026-10-20 09:00:00',
            'merged_at' => $mergedAt,
            'closed_at' => $mergedAt,
        ]);
    }

    private static function repository(GitConnection $connection, Project $project, string $name, int $forgeId): GitRepository
    {
        return GitRepository::query()->create([
            'git_connection_id' => $connection->id,
            'project_id' => $project->id,
            'forge_id' => $forgeId,
            'owner' => $connection->owner,
            'name' => $name,
            'full_name' => "{$connection->owner}/{$name}",
            'html_url' => "https://git.example/{$connection->owner}/{$name}",
            'clone_url' => "https://git.example/{$connection->owner}/{$name}.git",
            'ssh_url' => "git@git.example:{$connection->owner}/{$name}.git",
            'default_branch' => 'main',
        ]);
    }
}
