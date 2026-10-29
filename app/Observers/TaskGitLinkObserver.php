<?php

namespace App\Observers;

use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\TaskGitLink;
use App\Services\ProjectActivityRecorder;

/**
 * A pull request first linked to a task goes into the task's activity, by
 * the state it is in then: one first seen merged reads "merged", not
 * "opened". Commits and branches are not recorded. The forge did it, so no
 * one is credited, even when a backfill runs in the linking request.
 *
 * Only a pull request of a repository linked to the task's own project: the
 * task's activity is for everyone who sees the task, and a repository of
 * another project may be one they can't see.
 */
class TaskGitLinkObserver
{
    public function created(TaskGitLink $link): void
    {
        if ($link->linkable_type !== (new GitPullRequest)->getMorphClass()) {
            return;
        }

        $pullRequest = $link->linkable;

        if (! $pullRequest instanceof GitPullRequest || ! self::inOwnProject($link->task_id, $pullRequest)) {
            return;
        }

        ProjectActivityRecorder::record(
            taskId: $link->task_id,
            subject: $pullRequest,
            action: ProjectActivity::ACTION_CREATED,
            description: "Pull request #{$pullRequest->number} {$this->verb($pullRequest)}: ".ProjectActivityRecorder::truncate($pullRequest->title),
            system: true,
        );
    }

    /**
     * Whether the pull request's repository is linked to the task's project.
     */
    private static function inOwnProject(int $taskId, GitPullRequest $pullRequest): bool
    {
        return Task::withTrashed()
            ->whereKey($taskId)
            ->whereIn('project_id', GitRepository::query()->whereKey($pullRequest->git_repository_id)->select('project_id'))
            ->exists();
    }

    private function verb(GitPullRequest $pullRequest): string
    {
        return match ($pullRequest->state) {
            GitPullRequest::STATE_MERGED => 'merged',
            GitPullRequest::STATE_CLOSED => 'closed',
            default => 'opened',
        };
    }
}
