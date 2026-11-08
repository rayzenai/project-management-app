<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\Member;
use App\Support\Documents\DocumentTypes;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document, with what the viewer may do with it: `access` (`edit`, `view`
 * or null, {@see WorkspaceAccess::documentAccess()}), `frozen`, `can_delete`,
 * `can_share`, `can_move`; for whoever may share it, `shares_count` (the
 * people it is shared with whose share gives access now, when the shares
 * were loaded with their people; else null), and the project's `can_open`
 * (its Documents tab). The viewer is the request's user unless withViewer
 * names one. People by id and name only.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public static $wrap = null;

    private ?Authenticatable $viewer = null;

    public function withViewer(?Authenticatable $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $this->viewer ?? $request->user();
        /** @var Document $document */
        $document = $this->resource;
        $canShare = WorkspaceAccess::canShareDocument($viewer, $document);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'file_type' => $this->file_type,
            'kind' => DocumentTypes::label($this->file_type),
            'version' => $this->version,
            'size' => $this->size,
            'home' => $document->isPersonal() ? 'personal' : 'project',
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'slug' => $this->project->slug,
                'title' => $this->project->title,
                'label' => $this->project->label(),
                'can_open' => WorkspaceAccess::canUseProjectDocuments($viewer, $this->project),
            ] : null),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),
            'organization_access' => $this->organization_access,
            'access' => WorkspaceAccess::documentAccess($viewer, $document),
            'frozen' => WorkspaceAccess::isDocumentFrozen($document),
            'can_delete' => WorkspaceAccess::canDeleteDocument($viewer, $document),
            'can_share' => $canShare,
            'shares_count' => $canShare ? $this->sharesCount($document) : null,
            'can_move' => WorkspaceAccess::canMoveDocument($viewer, $document),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? ['id' => $this->updater->id, 'name' => $this->updater->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }

    /** Effective shares (WorkspaceAccess::canShareDocumentWith), counted from the loaded ones. */
    private function sharesCount(Document $document): ?int
    {
        if (! $document->relationLoaded('shares')) {
            return null;
        }

        return $document->shares
            ->filter(function (DocumentShare $share) use ($document): bool {
                // A deleted person's share loads no member (people are soft-deleted).
                $member = $share->relationLoaded('member') ? $share->getRelation('member') : $share->member()->first();

                return $member instanceof Member && WorkspaceAccess::canShareDocumentWith($document, $member);
            })
            ->count();
    }
}
