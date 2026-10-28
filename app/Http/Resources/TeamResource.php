<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
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
            'description' => $this->description,
            'color' => $this->color,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->name),
            'is_cross_organization' => (bool) $this->is_cross_organization,
            'can_edit' => WorkspaceAccess::canEditTeam($request->user(), $this->resource),
            'can_manage_roster' => WorkspaceAccess::canManageRosterOf($request->user(), $this->resource),
            'can_view_progress' => WorkspaceAccess::canViewTeamProgress($request->user(), $this->resource),
            'members_count' => $this->whenCounted('members'),
            'member_ids' => $this->whenLoaded('members', fn () => $this->members->map(fn (Member $m): int => $m->id)->all()),
            'leader_ids' => $this->whenLoaded('members', fn () => $this->members
                ->filter(fn (Member $m) => ($m->pivot->role ?? 'member') === 'leader')
                ->map(fn (Member $m): int => $m->id)
                ->values()
                ->all()),
        ];
    }
}
