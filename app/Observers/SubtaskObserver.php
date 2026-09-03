<?php

namespace App\Observers;

use App\Models\ProjectActivity;
use App\Models\Subtask;
use App\Services\ProjectActivityRecorder;

class SubtaskObserver
{
    public function created(Subtask $todo): void
    {
        ProjectActivityRecorder::record(
            taskId: $todo->task_id,
            subject: $todo,
            action: ProjectActivity::ACTION_CREATED,
            description: 'Todo added: "'.ProjectActivityRecorder::truncate($todo->body, 60).'"',
        );
    }

    public function updated(Subtask $todo): void
    {
        $original = $todo->getOriginal();

        if ($todo->wasChanged('is_done')) {
            $wasDone = (bool) ($original['is_done'] ?? false);
            $isDone = (bool) $todo->is_done;

            if (! $wasDone && $isDone) {
                ProjectActivityRecorder::record(
                    taskId: $todo->task_id,
                    subject: $todo,
                    action: ProjectActivity::ACTION_COMPLETED,
                    description: 'Todo done: "'.ProjectActivityRecorder::truncate($todo->body, 60).'"',
                );
            } elseif ($wasDone && ! $isDone) {
                ProjectActivityRecorder::record(
                    taskId: $todo->task_id,
                    subject: $todo,
                    action: ProjectActivity::ACTION_REOPENED,
                    description: 'Todo reopened: "'.ProjectActivityRecorder::truncate($todo->body, 60).'"',
                );
            }
        }

        if ($todo->wasChanged('body') || $todo->wasChanged('due_at')) {
            ProjectActivityRecorder::record(
                taskId: $todo->task_id,
                subject: $todo,
                action: ProjectActivity::ACTION_UPDATED,
                description: 'Todo edited',
            );
        }
    }

    public function deleted(Subtask $todo): void
    {
        ProjectActivityRecorder::record(
            taskId: $todo->task_id,
            subject: $todo,
            action: ProjectActivity::ACTION_DELETED,
            description: 'Todo deleted',
        );
    }

    public function restored(Subtask $todo): void
    {
        ProjectActivityRecorder::record(
            taskId: $todo->task_id,
            subject: $todo,
            action: ProjectActivity::ACTION_RESTORED,
            description: 'Todo restored',
        );
    }
}
