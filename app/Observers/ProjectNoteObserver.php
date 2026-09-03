<?php

namespace App\Observers;

use App\Models\ProjectActivity;
use App\Models\ProjectNote;
use App\Services\ProjectActivityRecorder;

class ProjectNoteObserver
{
    public function created(ProjectNote $note): void
    {
        ProjectActivityRecorder::record(
            taskId: $note->task_id,
            subject: $note,
            action: ProjectActivity::ACTION_CREATED,
            description: 'Note added: '.$note->type.' — "'.ProjectActivityRecorder::truncate($note->body, 60).'"',
        );
    }

    public function updated(ProjectNote $note): void
    {
        ProjectActivityRecorder::record(
            taskId: $note->task_id,
            subject: $note,
            action: ProjectActivity::ACTION_UPDATED,
            description: 'Note edited',
        );
    }

    public function deleted(ProjectNote $note): void
    {
        ProjectActivityRecorder::record(
            taskId: $note->task_id,
            subject: $note,
            action: ProjectActivity::ACTION_DELETED,
            description: 'Note deleted',
        );
    }

    public function restored(ProjectNote $note): void
    {
        ProjectActivityRecorder::record(
            taskId: $note->task_id,
            subject: $note,
            action: ProjectActivity::ACTION_RESTORED,
            description: 'Note restored',
        );
    }
}
