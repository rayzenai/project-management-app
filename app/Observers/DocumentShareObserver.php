<?php

namespace App\Observers;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\ProjectActivity;
use App\Services\ProjectActivityRecorder;

/**
 * Giving a project document to someone, changing and taking it back go into
 * its project's activity; a personal document's sharing writes none.
 */
class DocumentShareObserver
{
    public function created(DocumentShare $share): void
    {
        $this->record($share, ProjectActivity::ACTION_UPDATED, fn (string $title, string $name): string => "Shared document {$title} with {$name} (".self::can($share->permission).')');
    }

    public function updated(DocumentShare $share): void
    {
        if (! $share->wasChanged('permission')) {
            return;
        }

        $this->record($share, ProjectActivity::ACTION_UPDATED, fn (string $title, string $name): string => "Changed {$name}'s access to document {$title} to ".self::can($share->permission));
    }

    public function deleted(DocumentShare $share): void
    {
        $this->record($share, ProjectActivity::ACTION_UPDATED, fn (string $title, string $name): string => "Removed {$name}'s access to document {$title}");
    }

    public static function can(?string $level): string
    {
        return $level === Document::ACCESS_EDIT ? 'can edit' : 'can view';
    }

    /**
     * @param  callable(string, string): string  $describe
     */
    private function record(DocumentShare $share, string $action, callable $describe): void
    {
        $document = $share->document;

        if ($document->project === null) {
            return;
        }

        ProjectActivityRecorder::recordForProject(
            project: $document->project,
            subject: $document,
            action: $action,
            description: $describe(ProjectActivityRecorder::truncate($document->title), $share->member->name),
        );
    }
}
