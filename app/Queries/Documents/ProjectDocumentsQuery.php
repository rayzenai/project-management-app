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
 * newest change first, or the trash, newest first (callers have checked
 * canUseProjectDocuments); and one document for its editor page (callers
 * have checked its access level).
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
            ->with(['creator:id,name', 'updater:id,name', 'project.program'])
            ->orderByDesc($trashed ? 'deleted_at' : 'updated_at')
            ->orderByDesc('id')
            ->get();

        return array_values($documents
            ->map(fn (Document $document): array => (new DocumentResource($document))->withViewer($viewer)->resolve())
            ->all());
    }

    /**
     * One document with its versions, newest first, and its project (null for
     * a personal one; `can_open` says whether the viewer may open its
     * Documents tab).
     *
     * @return array{document: array<string, mixed>, versions: list<array<string, mixed>>, project: array{slug: string, title: string, label: string, can_open: bool}|null}
     */
    public function show(Document $document, ?Authenticatable $viewer): array
    {
        $document->loadMissing(['creator:id,name', 'updater:id,name', 'owner:id,name', 'project.program']);
        $project = $document->project;

        return [
            'document' => (new DocumentResource($document))->withViewer($viewer)->resolve(),
            'versions' => array_values(DocumentVersionResource::collection($document->versions()->with('user:id,name')->get())->resolve()),
            'project' => $project === null ? null : [
                'slug' => $project->slug,
                'title' => $project->title,
                'label' => $project->label(),
                'can_open' => WorkspaceAccess::canUseProjectDocuments($viewer, $project),
            ],
        ];
    }
}
