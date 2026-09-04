<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use Illuminate\Notifications\Notification;

// in-app (database) only — synchronous; add ShouldQueue when email/push channels are introduced
class TaskStatusChanged extends Notification
{
    use BuildsWorkspaceNotification;

    public function __construct(
        public Task $task,
        public string $statusLabel,
        public string $actorName,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'task_status_changed',
            'title' => 'Task status changed',
            'body' => "{$this->actorName} moved “{$this->task->title}” to {$this->statusLabel}.",
            'action' => "{$this->actorName} moved this to {$this->statusLabel}",
            'status' => $this->task->status,
            'task' => $this->taskRef($this->task),
            'actor' => ['name' => $this->actorName],
            'url' => $this->taskUrl($this->task),
        ];
    }
}
