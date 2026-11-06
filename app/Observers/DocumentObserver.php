<?php

namespace App\Observers;

use App\Models\Document;
use App\Models\ProjectActivity;
use App\Services\ProjectActivityRecorder;

/**
 * A document's rename, trash and restore go into its project's activity.
 * Its versions (created, uploaded, saved) are DocumentVersionObserver's.
 */
class DocumentObserver
{
    public function updated(Document $document): void
    {
        if (! $document->wasChanged('title')) {
            return;
        }

        $from = ProjectActivityRecorder::truncate((string) $document->getOriginal('title'));
        $to = ProjectActivityRecorder::truncate($document->title);

        ProjectActivityRecorder::recordForProject(
            project: $document->project,
            subject: $document,
            action: ProjectActivity::ACTION_UPDATED,
            description: "Renamed document {$from} to {$to}",
            changes: ['title' => ['from' => $document->getOriginal('title'), 'to' => $document->title]],
        );
    }

    public function deleted(Document $document): void
    {
        ProjectActivityRecorder::recordForProject(
            project: $document->project,
            subject: $document,
            action: ProjectActivity::ACTION_DELETED,
            description: 'Moved document '.ProjectActivityRecorder::truncate($document->title).' to the trash',
        );
    }

    public function restoring(Document $document): void
    {
        $document->deleted_by = null;
    }

    public function restored(Document $document): void
    {
        ProjectActivityRecorder::recordForProject(
            project: $document->project,
            subject: $document,
            action: ProjectActivity::ACTION_RESTORED,
            description: 'Restored document '.ProjectActivityRecorder::truncate($document->title),
        );
    }
}
