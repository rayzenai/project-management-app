<?php

namespace App\Http\Resources;

use App\Models\GitRepositoryGrant;
use App\Queries\Structure\PersonRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person's access to a linked repository. `waiting`: they have never
 * signed in to the forge (no login, or no forge username yet), so the forge
 * can't have them until they do. `effective`: the grant counts now
 * (GitRepositoryGrant::scopeEffective); one that doesn't stays stored and
 * gives nothing. No email.
 *
 * @mixin GitRepositoryGrant
 */
class GitRepositoryGrantResource extends JsonResource
{
    public static $wrap = null;

    private ?bool $effective = null;

    /**
     * Whether the grant counts, when the caller asked scopeEffective for a
     * whole list at once; otherwise the resource asks it for this grant.
     */
    public function withEffective(bool $effective): static
    {
        $this->effective = $effective;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'permission' => $this->permission,
            'waiting' => blank($this->member->user?->git_username),
            'effective' => $this->effective ?? $this->resource->isEffective(),
            'member' => PersonRef::of($this->member->loadMissing('designation:id,name')),
            'granted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
