<?php

namespace App\Http\Resources;

use App\Models\GitRepositoryGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person's access to a linked repository. `waiting`: they have never
 * signed in to the forge (no login, or no forge username yet), so the forge
 * can't have them until they do. No email.
 *
 * @mixin GitRepositoryGrant
 */
class GitRepositoryGrantResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'permission' => $this->permission,
            'waiting' => blank($this->member->user?->git_username),
            'member' => [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'title' => $this->member->title,
            ],
            'granted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
