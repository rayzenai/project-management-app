<?php

namespace App\Http\Resources;

use App\Models\DocumentShare;
use App\Queries\Structure\PersonRef;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One person a document is shared with: who (PersonRef, never an email),
 * Can edit or Can view, and whether it gives anything now
 * ({@see WorkspaceAccess::isShareEffective()}).
 *
 * @mixin DocumentShare
 */
class DocumentShareResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DocumentShare $share */
        $share = $this->resource;

        return [
            'id' => $share->id,
            'member' => PersonRef::of($share->member),
            'permission' => $share->permission,
            'effective' => WorkspaceAccess::isShareEffective($share),
        ];
    }
}
