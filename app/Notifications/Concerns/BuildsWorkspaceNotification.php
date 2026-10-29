<?php

namespace App\Notifications\Concerns;

use App\Models\Project;
use App\Models\Task;

trait BuildsWorkspaceNotification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{slug:string,title:string,project_slug:string}|null */
    protected function taskRef(?Task $task): ?array
    {
        if ($task === null) {
            return null;
        }

        return [
            'slug' => $task->slug,
            'title' => $task->title,
            'project_slug' => $task->project->slug ?? '',
        ];
    }

    /** @return array{slug:string,title:string} */
    protected function projectRef(Project $project): array
    {
        return ['slug' => $project->slug, 'title' => $project->title];
    }

    protected function projectUrl(Project $project): string
    {
        return "/workspace/projects/{$project->slug}?view=people";
    }

    protected function taskUrl(?Task $task): string
    {
        return $task === null
            ? '/workspace'
            : "/workspace/projects/{$task->project?->slug}/tasks/{$task->slug}";
    }
}
