<?php

namespace App\Observers;

use App\Models\ProjectActivity;
use App\Models\ProjectContact;
use App\Services\ProjectActivityRecorder;

class ProjectContactObserver
{
    public function created(ProjectContact $contact): void
    {
        $name = (string) $contact->name;
        $org = (string) $contact->organization;

        ProjectActivityRecorder::record(
            taskId: $contact->task_id,
            subject: $contact,
            action: ProjectActivity::ACTION_CREATED,
            description: 'Contact added: '.$name.($org !== '' ? '@'.$org : ''),
        );
    }

    public function updated(ProjectContact $contact): void
    {
        ProjectActivityRecorder::record(
            taskId: $contact->task_id,
            subject: $contact,
            action: ProjectActivity::ACTION_UPDATED,
            description: 'Contact '.((string) $contact->name).' edited',
        );
    }

    public function deleted(ProjectContact $contact): void
    {
        ProjectActivityRecorder::record(
            taskId: $contact->task_id,
            subject: $contact,
            action: ProjectActivity::ACTION_DELETED,
            description: 'Contact '.((string) $contact->name).' removed',
        );
    }

    public function restored(ProjectContact $contact): void
    {
        ProjectActivityRecorder::record(
            taskId: $contact->task_id,
            subject: $contact,
            action: ProjectActivity::ACTION_RESTORED,
            description: 'Contact '.((string) $contact->name).' restored',
        );
    }
}
