<?php

namespace App\Observers;

use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\ProjectActivity;
use App\Services\ProjectActivityRecorder;

/**
 * A merge goes into the activity of every task the pull request was already
 * linked to: GitIngest saves the pull request before its links change, and a
 * task it links in the same delivery is told by its link (TaskGitLinkObserver).
 * Only tasks of the repository's own project, as there. It never changes a
 * task's status.
 */
class GitPullRequestObserver
{
    public function updated(GitPullRequest $pullRequest): void
    {
        if (! $pullRequest->wasChanged('state') || $pullRequest->state !== GitPullRequest::STATE_MERGED) {
            return;
        }

        $projectId = GitRepository::query()->whereKey($pullRequest->git_repository_id)->value('project_id');

        foreach ($pullRequest->tasks()->where('tasks.project_id', $projectId)->pluck('tasks.id') as $taskId) {
            ProjectActivityRecorder::record(
                taskId: (int) $taskId,
                subject: $pullRequest,
                action: ProjectActivity::ACTION_UPDATED,
                description: "Pull request #{$pullRequest->number} merged",
                system: true,
            );
        }
    }
}
