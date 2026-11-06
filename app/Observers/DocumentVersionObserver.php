<?php

namespace App\Observers;

use App\Models\DocumentVersion;
use App\Models\ProjectActivity;
use App\Services\ProjectActivityRecorder;

/**
 * Each version of a document goes into its project's activity, credited to
 * whoever made it: the signed-in person for a new or uploaded document, the
 * editor's last user for a save (which arrives with no one signed in).
 */
class DocumentVersionObserver
{
    public function created(DocumentVersion $version): void
    {
        $document = $version->document;
        $title = ProjectActivityRecorder::truncate($document->title);

        $description = match ($version->source) {
            DocumentVersion::SOURCE_UPLOADED => "Uploaded document {$title}",
            DocumentVersion::SOURCE_EDITED => "Saved document {$title} (version {$version->version})",
            default => "Created document {$title}",
        };

        ProjectActivityRecorder::recordForProject(
            project: $document->project,
            subject: $document,
            action: $version->source === DocumentVersion::SOURCE_EDITED ? ProjectActivity::ACTION_UPDATED : ProjectActivity::ACTION_CREATED,
            description: $description,
            actorId: $version->user_id,
        );
    }
}
