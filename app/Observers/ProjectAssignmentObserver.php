<?php

namespace App\Observers;

use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Notifications\TaskAssigned;
use App\Services\ProjectActivityRecorder;

class ProjectAssignmentObserver
{
    public function created(ProjectAssignment $assignment): void
    {
        $name = $assignment->member->name ?? 'member #'.$assignment->member_id;

        ProjectActivityRecorder::record(
            taskId: $assignment->task_id,
            subject: $assignment,
            action: ProjectActivity::ACTION_CREATED,
            description: 'Assigned to '.$name.' ('.((string) $assignment->role).')',
        );

        $user = $assignment->member?->user;

        if ($user !== null) {
            $actor = auth()->user()->name ?? 'Someone';
            $user->notify(new TaskAssigned($assignment->task, $actor));
        }
    }

    public function updated(ProjectAssignment $assignment): void
    {
        $original = $assignment->getOriginal();
        $emittedSpecific = false;

        if ($assignment->wasChanged('priority')) {
            ProjectActivityRecorder::record(
                taskId: $assignment->task_id,
                subject: $assignment,
                action: ProjectActivity::ACTION_UPDATED,
                description: 'Priority: '.((string) ($original['priority'] ?? '—')).' → '.((string) ($assignment->priority ?? '—')),
                changes: ['from' => $original['priority'] ?? null, 'to' => $assignment->priority],
            );
            $emittedSpecific = true;
        }

        if ($assignment->wasChanged('personal_progress')) {
            $from = (int) ($original['personal_progress'] ?? 0);
            $to = (int) $assignment->personal_progress;
            ProjectActivityRecorder::record(
                taskId: $assignment->task_id,
                subject: $assignment,
                action: ProjectActivity::ACTION_PROGRESS_CHANGED,
                description: 'Personal progress: '.$from.'% → '.$to.'%',
                changes: ['from' => $from, 'to' => $to],
            );
            $emittedSpecific = true;
        }

        $otherFields = ['role', 'personal_due_at', 'personal_status_note'];
        $otherChanged = false;
        foreach ($otherFields as $field) {
            if ($assignment->wasChanged($field)) {
                $otherChanged = true;
                break;
            }
        }

        if ($otherChanged) {
            ProjectActivityRecorder::record(
                taskId: $assignment->task_id,
                subject: $assignment,
                action: ProjectActivity::ACTION_UPDATED,
                description: 'Assignment updated',
            );
        } elseif (! $emittedSpecific) {
            // Nothing meaningful changed; skip.
        }
    }

    public function deleted(ProjectAssignment $assignment): void
    {
        ProjectActivityRecorder::record(
            taskId: $assignment->task_id,
            subject: $assignment,
            action: ProjectActivity::ACTION_DELETED,
            description: 'Assignment removed',
        );
    }

    public function restored(ProjectAssignment $assignment): void
    {
        $name = $assignment->member->name ?? 'member #'.$assignment->member_id;

        ProjectActivityRecorder::record(
            taskId: $assignment->task_id,
            subject: $assignment,
            action: ProjectActivity::ACTION_RESTORED,
            description: 'Assignment to '.$name.' restored',
        );
    }
}
