<?php

namespace App\Queries\Documents;

use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Support\Documents\DocumentTypes;
use App\Support\WorkspaceAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Documents page (docs/superpowers/specs/2026-11-06-documents-workspace-design.md):
 * every document the viewer may open (Document::scopeVisibleTo), most
 * recently edited first, 50 at a time, filtered by show, project, type and
 * title; and the trash. A super-admin's organization switch ($organizationId)
 * narrows it to that organization's documents, keeping their own and those
 * shared with them.
 */
class DocumentLibraryQuery
{
    public const PAGE_SIZE = 50;

    /** The trash shows this many, newest first. */
    public const TRASH_LIMIT = 200;

    /**
     * @param  array{show: string, project: string|null, type: string|null, q: string|null}  $filters
     * @return array{documents: list<array<string, mixed>>, next_cursor: string|null, filters: array{show: string, project: string|null, type: string|null, q: string|null}, projects: list<array{slug: string, title: string, label: string}>, organization: array{id: int, name: string}|null, types: list<array{value: string, label: string}>, max_upload_mb: int, can_create_personal: bool, trashed_count: int}
     */
    public function page(User $viewer, array $filters, ?int $organizationId): array
    {
        $member = WorkspaceAccess::memberFor($viewer);
        $organization = $member?->organization;

        return [
            ...$this->list($viewer, $filters, $organizationId),
            'filters' => $filters,
            'projects' => $this->projects($viewer),
            'organization' => $organization === null ? null : ['id' => $organization->id, 'name' => $organization->name],
            'types' => array_map(fn (string $type): array => ['value' => $type, 'label' => DocumentTypes::label($type)], DocumentTypes::all()),
            'max_upload_mb' => intdiv((int) config('documents.max_upload_kilobytes'), 1024),
            'can_create_personal' => WorkspaceAccess::canCreatePersonalDocuments($viewer),
            'trashed_count' => count($this->trash($viewer, null, $organizationId)),
        ];
    }

    /**
     * One page of rows; `next_cursor` fetches the next (null at the end).
     *
     * @param  array{show: string, project: string|null, type: string|null, q: string|null}  $filters
     * @return array{documents: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(User $viewer, array $filters, ?int $organizationId, ?string $cursor = null): array
    {
        $query = $this->narrowed($this->filtered(Document::query()->visibleTo($viewer), $viewer, $filters), $viewer, $organizationId);

        $page = $query
            ->with(['creator:id,name', 'updater:id,name', 'owner', 'project.program'])
            ->orderByDesc('documents.updated_at')
            ->orderByDesc('documents.id')
            ->cursorPaginate(self::PAGE_SIZE, ['documents.*'], 'cursor', $cursor);

        return [
            'documents' => $this->rows($page->items(), $viewer),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }

    /**
     * The trash, newest first, at most TRASH_LIMIT. One project's (its tab):
     * all of its trashed documents the viewer may see, `can_delete` saying
     * which they may restore. Otherwise (the Documents page): what the viewer
     * may restore, plus, for a super-admin, frozen documents to read.
     *
     * @return list<array<string, mixed>>
     */
    public function trash(User $viewer, ?string $projectSlug, ?int $organizationId): array
    {
        $documents = $this->narrowed(Document::onlyTrashed()->visibleTo($viewer), $viewer, $organizationId)
            ->when($projectSlug !== null, fn (Builder $q) => $q->whereHas('project', fn (Builder $p) => $p->where('slug', $projectSlug)))
            ->with(['creator:id,name', 'updater:id,name', 'owner', 'project.program'])
            ->orderByDesc('documents.deleted_at')
            ->orderByDesc('documents.id')
            ->limit(self::TRASH_LIMIT)
            ->get();
        $rows = $this->rows($documents->all(), $viewer);

        if ($projectSlug !== null) {
            return $rows;
        }

        $superAdmin = WorkspaceAccess::isSuperAdmin($viewer);

        return array_values(array_filter($rows, fn (array $row): bool => $row['can_delete'] === true || ($superAdmin && $row['frozen'] === true)));
    }

    /**
     * @param  Builder<Document>  $query
     * @param  array{show: string, project: string|null, type: string|null, q: string|null}  $filters
     * @return Builder<Document>
     */
    private function filtered(Builder $query, User $viewer, array $filters): Builder
    {
        $member = WorkspaceAccess::memberFor($viewer);

        match ($filters['show']) {
            'mine' => $query->whereNull('documents.project_id')->where('documents.owner_id', $viewer->id),
            'shared' => $member === null
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('shares', fn (Builder $share) => $share->where('document_shares.member_id', $member->id)),
            'organization' => $query->whereNotNull('documents.organization_access'),
            'projects' => $query->whereNotNull('documents.project_id'),
            default => $query,
        };

        if ($filters['project'] !== null) {
            $query->whereHas('project', fn (Builder $p) => $p->where('slug', $filters['project']));
        }

        if ($filters['type'] !== null) {
            $query->where('documents.file_type', $filters['type']);
        }

        if ($filters['q'] !== null) {
            $like = '%'.mb_strtolower(str_replace(['%', '_'], '', $filters['q'])).'%';
            $query->whereRaw('LOWER(documents.title) LIKE ?', [$like]);
        }

        return $query;
    }

    /**
     * The organization switch: that organization's documents, plus the
     * viewer's own and those shared with them.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private function narrowed(Builder $query, User $viewer, ?int $organizationId): Builder
    {
        if ($organizationId === null) {
            return $query;
        }

        $memberId = WorkspaceAccess::memberFor($viewer)?->id;

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $own) => $own->whereNull('documents.project_id')->where('documents.owner_id', $viewer->id))
            ->when($memberId !== null, fn (Builder $shared) => $shared->orWhereHas('shares', fn (Builder $share) => $share->where('document_shares.member_id', $memberId)))
            ->orWhere(fn (Builder $there) => Document::whereInOrganization($there, $organizationId)));
    }

    /**
     * Projects whose documents the viewer may use, active ones by title: the
     * page's Where and project filter.
     *
     * @return list<array{slug: string, title: string, label: string}>
     */
    private function projects(User $viewer): array
    {
        return array_values(Project::query()
            ->documentsVisibleTo($viewer)
            ->active()
            ->with('program:id,name')
            ->orderBy('title')
            ->get(['id', 'slug', 'title', 'program_id'])
            ->map(fn (Project $project): array => ['slug' => $project->slug, 'title' => $project->title, 'label' => $project->label()])
            ->all());
    }

    /**
     * @param  array<int, Document>  $documents
     * @return list<array<string, mixed>>
     */
    private function rows(array $documents, User $viewer): array
    {
        return array_values(array_map(
            fn (Document $document): array => (new DocumentResource($document))->withViewer($viewer)->resolve(),
            $documents,
        ));
    }
}
