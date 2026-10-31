<?php

namespace App\Http\Resources;

use App\Models\Program;
use App\Queries\Structure\PersonRef;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Program
 */
class ProgramResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['organization', 'manager.designation:id,name']);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'organization' => ['id' => $this->organization->id, 'name' => $this->organization->name],
            'manager' => $this->manager === null ? null : PersonRef::of($this->manager),
            'projects_count' => $this->whenCounted('projects'),
            'can_manage' => WorkspaceAccess::canManagePrograms($request->user(), $this->organization_id),
            'can_create_project' => WorkspaceAccess::canCreateProjectInProgram($request->user(), $this->resource),
        ];
    }
}
