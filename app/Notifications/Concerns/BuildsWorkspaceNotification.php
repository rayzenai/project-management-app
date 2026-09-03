<?php

namespace App\Notifications\Concerns;

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

    protected function taskUrl(?Task $task): string
    {
        return $task === null
            ? '/workspace'
            : "/workspace/projects/{$task->project?->slug}/tasks/{$task->slug}";
    }
}
