<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\Project;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Facades\DB;

/**
 * A personal document moves into a project and belongs to it from then on:
 * the project's people use it, it stays with them whoever made it leaves,
 * and it never goes back. Its shares stay, judged against the project's
 * organization; opened to its owner's organization, it is closed when the
 * project is another organization's, so it never opens to a whole
 * organization nobody chose. Its files stay where they were written. Moving
 * is not editing: updated_at stays.
 */
class MoveDocumentService
{
    public function execute(Document $document, Project $project): ServiceResult
    {
        DB::transaction(function () use ($document, $project): void {
            $before = WorkspaceAccess::documentOrganizationId($document);

            Document::withoutTimestamps(function () use ($document, $project, $before): void {
                $document->project_id = $project->id;
                $document->owner_id = null;

                if ($before !== (int) $project->organization_id) {
                    $document->organization_access = null;
                }

                $document->save();
            });

            $document->setRelation('project', $project);
        });

        return ServiceResult::success($document, __('Moved :title to :project.', ['title' => $document->title, 'project' => $project->label()]));
    }
}
