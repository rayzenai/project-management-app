<?php

namespace App\Observers;

use App\Models\Document;
use App\Models\ProjectActivity;
use App\Services\ProjectActivityRecorder;

/**
 * A project document's rename, organization access, trash and restore go
 * into its project's activity; a personal document writes none. Its shares
 * are DocumentShareObserver's.
 * Its versions (created, uploaded, saved) are DocumentVersionObserver's.
 */
class DocumentObserver
{
    public function updated(Document $document): void
    {
        $project = $document->project;

        if ($project === null) {
            return;
        }

        $title = ProjectActivityRecorder::truncate($document->title);

        if ($document->wasChanged('title')) {
            $from = ProjectActivityRecorder::truncate((string) $document->getOriginal('title'));

            ProjectActivityRecorder::recordForProject(
                project: $project,
                subject: $document,
                action: ProjectActivity::ACTION_UPDATED,
                description: "Renamed document {$from} to {$title}",
                changes: ['title' => ['from' => $document->getOriginal('title'), 'to' => $document->title]],
            );
        }

        if ($document->wasChanged('organization_access')) {
            $organization = (string) $project->organization?->name;

            ProjectActivityRecorder::recordForProject(
                project: $project,
                subject: $document,
                action: ProjectActivity::ACTION_UPDATED,
                description: $document->organization_access === null
                    ? "Closed document {$title} to the organization"
                    : "Opened document {$title} to everyone in {$organization} (".DocumentShareObserver::can($document->organization_access).')',
            );
        }
    }

    public function deleted(Document $document): void
    {
        if ($document->project === null) {
            return;
        }

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
        if ($document->project === null) {
            return;
        }

        ProjectActivityRecorder::recordForProject(
            project: $document->project,
            subject: $document,
            action: ProjectActivity::ACTION_RESTORED,
            description: 'Restored document '.ProjectActivityRecorder::truncate($document->title),
        );
    }
}
