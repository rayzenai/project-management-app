<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Models\Team;
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
