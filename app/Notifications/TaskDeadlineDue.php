<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

// in-app (database) only — synchronous; add ShouldQueue when email/push channels are introduced
class TaskDeadlineDue extends Notification
{
    use BuildsWorkspaceNotification;

    /**
     * @param  'heads_up'|'due_today'|'overdue'  $window
     */
    public function __construct(public Task $task, public string $window) {}

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'task_deadline_due',
            'title' => 'Deadline reminder',
            'body' => $this->bodyForWindow(),
            'action' => $this->actionForWindow(),
            'task' => $this->taskRef($this->task),
            'actor' => null,
            'url' => $this->taskUrl($this->task),
        ];
    }

    protected function bodyForWindow(): string
    {
        return match ($this->window) {
            'due_today' => "“{$this->task->title}” is due today.",
            'overdue' => "“{$this->task->title}” is {$this->overdueLabel()}.",
            default => "“{$this->task->title}” is due soon.",
        };
    }

    protected function actionForWindow(): string
    {
        return match ($this->window) {
            'due_today' => 'Due today',
            'overdue' => ucfirst($this->overdueLabel()),
            default => 'Due soon',
        };
    }

    protected function overdueLabel(): string
    {
        if ($this->task->deadline_at === null) {
            return 'overdue';
        }

        $days = (int) Carbon::today()->diffInDays($this->task->deadline_at, false);

        return $days < 0 ? abs($days).'d overdue' : 'overdue';
    }
}
