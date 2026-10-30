<?php

namespace App\Observers;

use App\Jobs\SyncRepositoryAccess;
use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Services\ProjectActivityRecorder;
use App\Support\Background;

/**
 * Giving someone access to a repository, changing it and taking it away go
 * into its project's activity, and the repository's forge access is synced
 * off the request.
 */
class GitRepositoryGrantObserver
{
    public function created(GitRepositoryGrant $grant): void
    {
        $context = $this->context($grant);

        if ($context === null) {
            return;
        }

        [$repository, $project, $member] = $context;

        ProjectActivityRecorder::recordForProject(
            project: $project,
            subject: $grant,
            action: ProjectActivity::ACTION_CREATED,
            description: "gave {$member->name} {$grant->permission} access to {$repository->full_name}",
            changes: ['member_id' => $member->id, 'permission' => $grant->permission],
        );

        Background::dispatch(new SyncRepositoryAccess($repository));
    }

    public function updated(GitRepositoryGrant $grant): void
    {
        if (! $grant->wasChanged('permission')) {
            return;
        }

        $context = $this->context($grant);

        if ($context === null) {
            return;
        }

        [$repository, $project, $member] = $context;

        ProjectActivityRecorder::recordForProject(
            project: $project,
            subject: $grant,
            action: ProjectActivity::ACTION_UPDATED,
            description: "changed {$member->name}'s access to {$repository->full_name} to {$grant->permission}",
            changes: ['member_id' => $member->id, 'from' => $grant->getOriginal('permission'), 'to' => $grant->permission],
        );

        Background::dispatch(new SyncRepositoryAccess($repository));
    }

    public function deleted(GitRepositoryGrant $grant): void
    {
        $context = $this->context($grant);

        if ($context === null) {
            return;
        }

        [$repository, $project, $member] = $context;

        ProjectActivityRecorder::recordForProject(
            project: $project,
            subject: $grant,
            action: ProjectActivity::ACTION_DELETED,
            description: "removed {$member->name}'s access to {$repository->full_name}",
            changes: ['member_id' => $member->id, 'permission' => $grant->permission],
        );

        Background::dispatch(new SyncRepositoryAccess($repository));
    }

    /**
     * @return array{0: GitRepository, 1: Project, 2: Member}|null
     */
    private function context(GitRepositoryGrant $grant): ?array
    {
        $repository = GitRepository::query()->find($grant->git_repository_id);
        $project = $repository === null ? null : Project::query()->find($repository->project_id);
        $member = Member::withTrashed()->find($grant->member_id);

        return $repository && $project && $member ? [$repository, $project, $member] : null;
    }
}
