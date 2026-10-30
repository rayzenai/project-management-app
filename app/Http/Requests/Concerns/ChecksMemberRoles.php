<?php

namespace App\Http\Requests\Concerns;

use App\Models\Member;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use App\Services\Workspace\UpdateMemberRolesService;
use App\Support\WorkspaceAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The role fields of the person form, shared by StoreMemberRequest and
 * UpdateMemberRequest: the programs and projects the person manages and the
 * teams they are on, each the complete wanted set (a field left out leaves
 * that role as it is). Applied by {@see UpdateMemberRolesService}, which also
 * refuses taking a manager role away and a manager role for an inactive
 * person.
 */
trait ChecksMemberRoles
{
    /**
     * @return array<string, mixed>
     */
    protected function roleRules(): array
    {
        return [
            'managed_program_ids' => ['sometimes', 'array'],
            'managed_program_ids.*' => ['integer', Rule::exists('programs', 'id')],
            'managed_project_ids' => ['sometimes', 'array'],
            'managed_project_ids.*' => ['integer', Rule::exists('projects', 'id')],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * The role fields sent, for UpdateMemberRolesService.
     *
     * @return array{managed_program_ids?: list<int>, managed_project_ids?: list<int>, team_ids?: list<int>}
     */
    public function roles(): array
    {
        $roles = [];

        if (($ids = $this->ids($this->validated('managed_program_ids'))) !== null) {
            $roles['managed_program_ids'] = $ids;
        }

        if (($ids = $this->ids($this->validated('managed_project_ids'))) !== null) {
            $roles['managed_project_ids'] = $ids;
        }

        if (($ids = $this->ids($this->validated('team_ids'))) !== null) {
            $roles['team_ids'] = $ids;
        }

        return $roles;
    }

    /**
     * Every program, project or team the request gives the person or takes
     * from them must be in an organization the user administers, and suit
     * the person as they will be after it ($person: moved to another
     * organization, or not saved yet; $current: as stored, null on create).
     * A role the person holds and keeps is no change, wherever it is.
     */
    protected function checkRoles(Validator $validator, Member $person, ?Member $current): void
    {
        $user = $this->user();

        $programIds = $this->roleIds('managed_program_ids');

        if ($programIds !== null) {
            $held = $current?->managedPrograms()->pluck('id')->all() ?? [];

            foreach (Program::query()->whereKey(array_diff($programIds, $held))->orderBy('name')->get() as $program) {
                if (! WorkspaceAccess::administers($user, $program->organization_id)
                    || (int) $program->organization_id !== (int) $person->organization_id) {
                    $validator->errors()->add('managed_program_ids', "The {$program->name} program belongs to another organization.");

                    return;
                }
            }
        }

        $projectIds = $this->roleIds('managed_project_ids');

        if ($projectIds !== null) {
            $held = $current === null ? [] : Project::query()
                ->whereHas('teams', fn ($t) => $t->whereIn('teams.id', $current->ledTeams()->select('teams.id')))
                ->pluck('id')
                ->all();

            foreach (Project::query()->whereKey(array_diff($projectIds, $held))->with('teams')->orderBy('title')->get() as $project) {
                // A teamless project gets its team, in its organization, when the manager is appointed.
                $team = $project->team() ?? new Team(['organization_id' => $project->organization_id]);

                if (! WorkspaceAccess::administers($user, $project->organization_id)
                    || ! WorkspaceAccess::canAppointAsProjectManager($user, $team, $person)) {
                    $validator->errors()->add('managed_project_ids', "{$project->title} belongs to another organization.");

                    return;
                }
            }
        }

        $teamIds = $this->roleIds('team_ids');

        if ($teamIds === null) {
            return;
        }

        $onNow = $current?->teams()->pluck('teams.id')->all() ?? [];

        foreach (Team::query()->whereKey(array_diff($teamIds, $onNow))->with('projects')->orderBy('name')->get() as $team) {
            if (! WorkspaceAccess::administers($user, $team->organization_id) || ! WorkspaceAccess::canJoinTeam($person, $team)) {
                $validator->errors()->add('team_ids', ($team->project()->title ?? $team->name).' belongs to another organization.');

                return;
            }
        }

        if ($current === null) {
            return;
        }

        // A team they manage keeps them (the manager role wins), so only
        // the others are taken off.
        $leaving = $current->teams()
            ->wherePivot('role', '!=', 'leader')
            ->whereNotIn('teams.id', $teamIds)
            ->with('projects')
            ->orderBy('teams.name')
            ->get();

        foreach ($leaving as $team) {
            if (! WorkspaceAccess::administers($user, $team->organization_id)) {
                $validator->errors()->add('team_ids', ($team->project()->title ?? $team->name).' belongs to another organization.');

                return;
            }
        }
    }

    /**
     * While validating: the ids sent, which passed the rules.
     *
     * @return list<int>|null null when the field was not sent
     */
    private function roleIds(string $field): ?array
    {
        return $this->ids($this->input($field));
    }

    /**
     * @return list<int>|null
     */
    private function ids(mixed $value): ?array
    {
        return is_array($value)
            ? array_values(array_unique(array_map(fn ($id): int => (int) $id, $value)))
            : null;
    }
}
