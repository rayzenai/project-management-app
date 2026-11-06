<?php

namespace App\Queries\Documents;

use App\Models\Document;
use App\Models\Member;
use App\Queries\Structure\PersonRef;
use App\Support\WorkspaceAccess;

/**
 * Whom the Share dialog offers: active people of the document's
 * organization, not a personal document's owner and not anyone it is
 * already shared with, by name; PersonRefs, never emails. The twin of
 * WorkspaceAccess::canShareDocumentWith (keep them in step).
 */
class DocumentShareCandidatesQuery
{
    public const LIMIT = 8;

    /**
     * @return list<array{id: int, name: string, designation: string|null}>
     */
    public function get(Document $document, string $term): array
    {
        $organizationId = WorkspaceAccess::documentOrganizationId($document);

        if ($organizationId === null) {
            return [];
        }

        $like = '%'.mb_strtolower(str_replace(['%', '_'], '', trim($term))).'%';

        return array_values(Member::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->whereNotIn('id', $document->shares()->select('member_id'))
            ->when($document->isPersonal(), fn ($q) => $q->where(fn ($owner) => $owner->whereNull('user_id')->orWhere('user_id', '!=', $document->owner_id)))
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]))
            ->with('designation:id,name')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Member $member): array => PersonRef::of($member))
            ->all());
    }
}
