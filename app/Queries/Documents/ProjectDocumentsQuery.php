<?php

namespace App\Queries\Documents;

use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentVersionResource;
use App\Models\Document;
use App\Models\Project;
use App\Support\Documents\DocumentTypes;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A project's documents, for its Documents tab (web) and the API list:
 * newest change first, or the trash, newest first. Callers have checked
 * canUseProjectDocuments.
 */
class ProjectDocumentsQuery
{
    /**
     * The Documents tab on the project page.
     *
     * @return array{documents: list<array<string, mixed>>, trashed_count: int, types: list<array{value: string, label: string}>, max_upload_mb: int}
     */
    public function tab(Project $project, ?Authenticatable $viewer): array
    {
        return [
            'documents' => $this->list($project, $viewer),
            'trashed_count' => Document::onlyTrashed()->where('project_id', $project->id)->count(),
            'types' => array_map(fn (string $type): array => ['value' => $type, 'label' => DocumentTypes::label($type)], DocumentTypes::all()),
            'max_upload_mb' => intdiv((int) config('documents.max_upload_kilobytes'), 1024),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Project $project, ?Authenticatable $viewer, bool $trashed = false): array
    {
        $documents = Document::query()
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->where('project_id', $project->id)
            ->with(['creator:id,name', 'updater:id,name'])
            ->orderByDesc($trashed ? 'deleted_at' : 'updated_at')
            ->orderByDesc('id')
            ->get();

        return array_values($documents
            ->map(fn (Document $document): array => (new DocumentResource($document))
                ->withCanDelete(WorkspaceAccess::canDeleteDocument($viewer, $document))
                ->resolve())
            ->all());
    }

    /**
     * One document with its versions, newest first.
     *
     * @return array{document: array<string, mixed>, versions: list<array<string, mixed>>}
     */
    public function show(Document $document, Project $project, ?Authenticatable $viewer): array
    {
        $document->loadMissing(['creator:id,name', 'updater:id,name']);

        return [
            'document' => (new DocumentResource($document))
                ->withCanDelete(WorkspaceAccess::canDeleteDocument($viewer, $document))
                ->resolve(),
            'versions' => array_values(DocumentVersionResource::collection($document->versions()->with('user:id,name')->get())->resolve()),
        ];
    }
}
