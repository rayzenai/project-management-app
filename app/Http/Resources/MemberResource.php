<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Member
 */
class MemberResource extends JsonResource
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
            'email' => $this->email,
            'title' => $this->title,
            'user_id' => $this->user_id,
            'is_active' => (bool) $this->is_active,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->name),
            'is_office_admin' => (bool) $this->is_office_admin,
            'can_manage' => WorkspaceAccess::canManageMember($request->user(), $this->resource),
            'team_ids' => $this->whenLoaded('teams', fn () => $this->teams->map(fn (Team $t): int => $t->id)->all()),
            'led_team_ids' => $this->whenLoaded('teams', fn () => $this->teams
                ->filter(fn (Team $t) => ($t->pivot->role ?? 'member') === 'leader')
                ->map(fn (Team $t): int => $t->id)
                ->values()
                ->all()),
        ];
    }
}
