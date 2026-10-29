<?php

namespace App\Notifications;

use App\Models\Project;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use Illuminate\Notifications\Notification;

// in-app (database) only — synchronous, like the task notifications
class AddedToProjectTeam extends Notification
{
    use BuildsWorkspaceNotification;

    public function __construct(public Project $project, public string $actorName) {}

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'team_added',
            'title' => $this->project->title,
            'body' => "{$this->actorName} added you to the {$this->project->title} team.",
            'action' => "{$this->actorName} added you to the team",
            'task' => null,
            'project' => $this->projectRef($this->project),
            'actor' => ['name' => $this->actorName],
            'url' => $this->projectUrl($this->project),
        ];
    }
}
