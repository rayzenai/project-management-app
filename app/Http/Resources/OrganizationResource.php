<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'members_count' => $this->whenCounted('members'),
            'teams_count' => $this->whenCounted('teams'),
            'projects_count' => $this->whenCounted('projects'),
            'office_admins' => $this->whenLoaded('members', fn () => $this->members
                ->filter(fn (Member $m): bool => $m->is_office_admin)
                ->map(fn (Member $m): array => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email])
                ->values()
                ->all()),
        ];
    }
}
