<?php

namespace App\Observers;

use App\Models\ProjectActivity;
use App\Models\Task;
use App\Notifications\TaskStatusChanged;
use App\Services\ProjectActivityRecorder;

class TaskObserver
{
    /**
     * Attributes that get a dedicated "field updated" activity row.
     *
     * @var array<string, string>
     */
    private const TRACKED_FIELD_LABELS = [
        'status_note' => 'Status note',
        'responsible_ministry' => 'Responsible ministry',
        'deadline_at' => 'Deadline',
    ];

    public function created(Task $item): void
    {
        ProjectActivityRecorder::record(
            taskId: $item->id,
            subject: $item,
            action: ProjectActivity::ACTION_CREATED,
            description: 'Plan #'.$item->item_number.' — "'.ProjectActivityRecorder::truncate($item->title, 80).'" created',
        );
    }

    public function restored(Task $item): void
    {
        ProjectActivityRecorder::record(
            taskId: $item->id,
            subject: $item,
            action: ProjectActivity::ACTION_RESTORED,
            description: 'Plan #'.$item->item_number.' — "'.ProjectActivityRecorder::truncate($item->title, 80).'" restored',
        );
    }

    public function updated(Task $item): void
    {
        $original = $item->getOriginal();

        // status change
        if ($item->wasChanged('status')) {
            $from = $original['status'] ?? null;
            $to = $item->status;
            ProjectActivityRecorder::record(
                taskId: $item->id,
                subject: $item,
                action: ProjectActivity::ACTION_STATUS_CHANGED,
                description: 'Status: '.($from ?? '—').' → '.$to,
                changes: ['from' => $from, 'to' => $to],
            );

            $this->notifyStatusChange($item);
        }

        // progress change
        if ($item->wasChanged('progress')) {
            $from = (int) ($original['progress'] ?? 0);
            $to = (int) $item->progress;

            ProjectActivityRecorder::record(
                taskId: $item->id,
                subject: $item,
                action: ProjectActivity::ACTION_PROGRESS_CHANGED,
                description: 'Progress: '.$from.'% → '.$to.'%',
                changes: ['from' => $from, 'to' => $to],
            );

            if ($from !== 100 && $to === 100) {
                ProjectActivityRecorder::record(
                    taskId: $item->id,
                    subject: $item,
                    action: ProjectActivity::ACTION_COMPLETED,
                    description: 'Plan marked complete',
                    changes: ['from' => $from, 'to' => $to],
                );
            } elseif ($from === 100 && $to !== 100) {
                ProjectActivityRecorder::record(
                    taskId: $item->id,
                    subject: $item,
                    action: ProjectActivity::ACTION_REOPENED,
                    description: 'Plan reopened',
                    changes: ['from' => $from, 'to' => $to],
                );
            }
        }

        // generic tracked fields
        foreach (self::TRACKED_FIELD_LABELS as $field => $label) {
            if (! $item->wasChanged($field)) {
                continue;
            }

            $from = $original[$field] ?? null;
            $to = $item->getAttribute($field);

            $fromDisplay = ProjectActivityRecorder::truncate((string) $from, 100);
            $toDisplay = ProjectActivityRecorder::truncate((string) $to, 100);

            ProjectActivityRecorder::record(
                taskId: $item->id,
                subject: $item,
                action: ProjectActivity::ACTION_UPDATED,
                description: $label.' updated: '.($fromDisplay === '' ? '—' : $fromDisplay).' → '.($toDisplay === '' ? '—' : $toDisplay),
                changes: ['field' => $field, 'from' => $from, 'to' => is_scalar($to) ? $to : (string) $to],
            );
        }
    }

    /**
     * Notify a task's assignees (linked users) — except the acting user —
     * when the task transitions into a completed or failed status.
     */
    private function notifyStatusChange(Task $item): void
    {
        $notifyStatuses = [...Task::completeStatuses(), 'failed'];

        if (! in_array($item->status, $notifyStatuses, true)) {
            return;
        }

        $actorId = auth()->id();
        $actorName = auth()->user()->name ?? 'Someone';
        $statusLabel = $item->status_label ?? $item->status;

        $item->loadMissing('assignments.member.user');

        $item->assignments
            ->pluck('member.user')
            ->filter()
            ->reject(fn ($user) => $user->id === $actorId)
            ->unique('id')
            ->each(fn ($user) => $user->notify(new TaskStatusChanged($item, $statusLabel, $actorName)));
    }
}
