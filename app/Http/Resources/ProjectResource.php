<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'title_np' => $this->title_np,
            'description' => $this->description,
            'description_np' => $this->description_np,
            'is_public' => (bool) $this->is_public,
            'is_archived' => $this->archived_at !== null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'can_archive' => WorkspaceAccess::canArchiveProject($request->user(), $this->resource),
            'can_manage_access' => WorkspaceAccess::canManageProjectAccess($request->user(), $this->resource),
            'teams' => $this->whenLoaded('teams', fn () => $this->teams->map(fn (Team $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
            ])->all()),
            'tasks_count' => $this->whenCounted('tasks'),
            'team_ids' => $this->whenLoaded('teams', fn () => $this->teams->map(fn (Team $t): int => $t->id)->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
