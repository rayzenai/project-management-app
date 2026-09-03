<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use Illuminate\Notifications\Notification;

// in-app (database) only — synchronous; add ShouldQueue when email/push channels are introduced
class MentionedInComment extends Notification
{
    use BuildsWorkspaceNotification;

    public function __construct(
        public Task $task,
        public string $actorName,
        public string $excerpt,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'mentioned_in_comment',
            'title' => 'You were mentioned',
            'body' => "{$this->actorName} mentioned you: “{$this->excerpt}”.",
            'task' => $this->taskRef($this->task),
            'actor' => ['name' => $this->actorName],
            'url' => $this->taskUrl($this->task),
        ];
    }
}
