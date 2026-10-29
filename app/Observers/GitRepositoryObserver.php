<?php

namespace App\Observers;

use App\Models\GitRepository;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Services\ProjectActivityRecorder;

/**
 * Linking and unlinking a repository go into its project's activity.
 */
class GitRepositoryObserver
{
    public function created(GitRepository $repository): void
    {
        ProjectActivityRecorder::recordForProject(
            project: $repository->project,
            subject: $repository,
            action: ProjectActivity::ACTION_CREATED,
            description: "Linked repository {$repository->full_name}",
        );
    }

    public function deleted(GitRepository $repository): void
    {
        $project = Project::query()->find($repository->project_id);

        if ($project === null) {
            return;
        }

        ProjectActivityRecorder::recordForProject(
            project: $project,
            subject: $repository,
            action: ProjectActivity::ACTION_DELETED,
            description: "Unlinked repository {$repository->full_name}",
        );
    }
}
