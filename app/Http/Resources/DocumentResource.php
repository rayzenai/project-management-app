<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Support\Documents\DocumentTypes;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project's document. `can_delete` is the viewer's
 * {@see WorkspaceAccess::canDeleteDocument()}, given by the query that
 * lists them (it knows the project). People by id and name only.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public static $wrap = null;

    private bool $canDelete = false;

    public function withCanDelete(bool $canDelete): static
    {
        $this->canDelete = $canDelete;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'file_type' => $this->file_type,
            'kind' => DocumentTypes::label($this->file_type),
            'version' => $this->version,
            'size' => $this->size,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? ['id' => $this->updater->id, 'name' => $this->updater->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'can_delete' => $this->canDelete,
        ];
    }
}
