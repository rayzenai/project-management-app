<?php

namespace App\Notifications;

use App\Models\Program;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use Illuminate\Notifications\Notification;

// in-app (database) only — synchronous, like the task notifications
class MadeProgramManager extends Notification
{
    use BuildsWorkspaceNotification;

    public function __construct(public Program $program, public string $actorName) {}

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'made_program_manager',
            'title' => $this->program->name,
            'body' => "{$this->actorName} made you the manager of the {$this->program->name} program.",
            'action' => "{$this->actorName} made you its manager",
            'task' => null,
            'program' => ['slug' => $this->program->slug, 'name' => $this->program->name],
            'actor' => ['name' => $this->actorName],
            'url' => "/workspace/programs/{$this->program->slug}",
        ];
    }
}
