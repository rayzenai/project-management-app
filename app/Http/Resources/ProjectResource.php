<?php

namespace App\Http\Resources;

use App\Models\Project;
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
            'code' => $this->code,
            'key' => $this->key,
            'title' => $this->title,
            'title_np' => $this->title_np,
            'description' => $this->description,
            'description_np' => $this->description_np,
            'is_public' => (bool) $this->is_public,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->name),
            'is_archived' => $this->archived_at !== null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'can_archive' => WorkspaceAccess::canArchiveProject($request->user(), $this->resource),
            'can_manage_access' => WorkspaceAccess::canManageProjectAccess($request->user(), $this->resource),
            'can_view_progress' => WorkspaceAccess::canViewProjectProgress($request->user(), $this->resource),
            // Choosing the manager and publishing are the office admin's (or a super-admin's).
            'can_choose_manager' => WorkspaceAccess::administers($request->user(), $this->organization_id),
            'can_change_key' => WorkspaceAccess::canChangeProjectKey($request->user(), $this->resource),
            'can_publish' => WorkspaceAccess::canPublishProject($request->user(), $this->organization_id),
            'program' => $this->whenLoaded('program', fn (): ?array => $this->program === null ? null : [
                'slug' => $this->program->slug,
                'name' => $this->program->name,
            ]),
            'team' => $this->whenLoaded('teams', fn (): ?array => ($team = $this->team()) === null ? null : [
                'id' => $team->id,
                'is_cross_organization' => (bool) $team->is_cross_organization,
                'members_count' => $team->members_count ?? $team->members()->count(),
            ]),
            'manager' => $this->whenLoaded('teams', fn (): ?array => ($manager = $this->manager()) === null ? null : [
                'id' => $manager->id,
                'name' => $manager->name,
                'title' => $manager->title,
            ]),
            'tasks_count' => $this->whenCounted('tasks'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
