<?php

namespace App\Queries\Documents;

use App\Http\Resources\DocumentShareResource;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\Organization;
use App\Support\WorkspaceAccess;

/**
 * Who has a document, for its Share dialog: where it lives (Only you, or
 * Everyone on its project), its organization and organization access, and
 * the people it is shared with, by name. Callers have checked
 * canShareDocument.
 */
class DocumentSharingQuery
{
    /**
     * @return array{home: array{type: string, project: array{slug: string, title: string}|null}, organization: array{id: int, name: string}|null, organization_access: string|null, shares: list<array<string, mixed>>}
     */
    public function for(Document $document): array
    {
        $document->loadMissing(['project', 'owner']);
        $organizationId = WorkspaceAccess::documentOrganizationId($document);
        $organization = $organizationId === null ? null : Organization::query()->find($organizationId, ['id', 'name']);

        $shares = $document->shares()
            ->with('member.designation')
            ->get()
            ->each(fn (DocumentShare $share) => $share->setRelation('document', $document))
            ->sortBy(fn (DocumentShare $share): string => mb_strtolower($share->member->name))
            ->values();

        return [
            'home' => [
                'type' => $document->isPersonal() ? 'personal' : 'project',
                'project' => $document->project === null ? null : ['slug' => $document->project->slug, 'title' => $document->project->title],
            ],
            'organization' => $organization === null ? null : ['id' => $organization->id, 'name' => $organization->name],
            'organization_access' => $document->organization_access,
            'shares' => array_values(DocumentShareResource::collection($shares)->resolve()),
        ];
    }
}
