<?php

namespace App\Notifications;

use App\Models\Project;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use Illuminate\Notifications\Notification;

// in-app (database) only — synchronous, like the task notifications
class MadeProjectManager extends Notification
{
    use BuildsWorkspaceNotification;

    public function __construct(public Project $project, public string $actorName) {}

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'made_manager',
            'title' => $this->project->title,
            'body' => "{$this->actorName} made you the manager of {$this->project->title}.",
            'action' => "{$this->actorName} made you its manager",
            'task' => null,
            'project' => $this->projectRef($this->project),
            'actor' => ['name' => $this->actorName],
            'url' => $this->projectUrl($this->project),
        ];
    }
}
