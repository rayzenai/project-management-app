<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Member
 */
class MemberResource extends JsonResource
{
    public static $wrap = null;

    /**
     * What to load for the role keys (`team_ids`, `led_team_ids`,
     * `managed_project_ids`, `managed_program_ids`).
     */
    public const ROLE_RELATIONS = ['teams:id', 'teams.projects:id', 'managedPrograms:id,manager_id'];

    /**
     * What to load for `designation`.
     */
    public const STRUCTURE_RELATIONS = ['designation:id,name'];

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
            'led_team_ids' => $this->whenLoaded('teams', fn () => $this->loadedLedTeams()->map(fn (Team $t): int => $t->id)->values()->all()),
            'managed_project_ids' => $this->when(
                $this->relationLoaded('teams') && $this->teams->every(fn (Team $t): bool => $t->relationLoaded('projects')),
                fn () => $this->loadedLedTeams()->flatMap(fn (Team $t) => $t->projects->map(fn (Project $p): int => $p->id))->values()->all(),
            ),
            'managed_program_ids' => $this->whenLoaded('managedPrograms', fn () => $this->managedPrograms->map(fn (Program $p): int => $p->id)->all()),
            'designation' => $this->whenLoaded('designation', fn (): ?array => $this->designation === null ? null : ['id' => $this->designation->id, 'name' => $this->designation->name]),
            'reports_to_id' => $this->reports_to_id,
            'attendance_exempt' => (bool) $this->attendance_exempt,
        ];
    }

    /**
     * The loaded teams this person manages (pivot role = leader).
     *
     * @return Collection<int, Team>
     */
    private function loadedLedTeams(): Collection
    {
        return $this->teams->filter(fn (Team $t) => ($t->pivot->role ?? 'member') === 'leader');
    }
}
