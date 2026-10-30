<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use App\Support\ProjectKeys;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Its manager edits the project's details. Choosing the manager belongs to
 * whoever administers its organization and to its program's manager; making
 * it public, its key and its program to whoever administers its
 * organization; letting people from other organizations onto its team is a
 * super-admin's call. Anyone else's values for those are dropped (read them
 * through `validated()`).
 */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && WorkspaceAccess::canManageProjectAccess($this->user(), $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'title_np' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'description_np' => ['sometimes', 'nullable', 'string'],
            'key' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{1,10}$/'],
            'is_public' => ['sometimes', 'nullable', 'boolean'],
            'manager_id' => ['sometimes', 'required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
            'is_cross_organization' => ['sometimes', 'boolean'],
            'program_id' => ['sometimes', 'nullable', 'integer', Rule::exists('programs', 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('key'))) {
            $this->merge(['key' => strtoupper(trim($this->input('key')))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $project = $this->project();

            if ($this->has('key') && WorkspaceAccess::canChangeProjectKey($this->user(), $project)
                && ProjectKeys::isTaken($project->organization_id, (string) $this->input('key'), $project->id)) {
                $validator->errors()->add('key', 'That key is already used in this organization.');

                return;
            }

            // Only for those whose program_id is kept; anyone else's is dropped.
            if ($this->filled('program_id') && WorkspaceAccess::administers($this->user(), $project->organization_id)
                && (int) Program::query()->whereKey($this->integer('program_id'))->value('organization_id') !== (int) $project->organization_id) {
                $validator->errors()->add('program_id', 'That program belongs to another organization.');

                return;
            }

            $current = $project->team();
            // The team as it will be after this request, for who may join it.
            $team = new Team([
                'organization_id' => $project->organization_id,
                'is_cross_organization' => $this->crossOrganization($current),
            ]);

            if ($this->has('manager_id') && $this->appointsManager()) {
                $manager = Member::query()->with('user')->find($this->integer('manager_id'));

                if ($manager === null || ! $manager->is_active || ! WorkspaceAccess::canAppointAsProjectManager($this->user(), $team, $manager)) {
                    $validator->errors()->add('manager_id', "The manager must be an active person who can join the project's team.");

                    return;
                }
            }

            foreach ($current?->members()->with('user')->get() ?? [] as $member) {
                if (! WorkspaceAccess::canJoinTeam($member, $team)) {
                    $validator->errors()->add('is_cross_organization', "{$member->name} belongs to another organization. Take them off the team first.");

                    return;
                }
            }
        });
    }

    private function project(): Project
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project;
    }

    /**
     * Whether the caller may choose the project's manager. A project still
     * without a team gets one from whoever administers its organization.
     */
    private function appointsManager(): bool
    {
        $project = $this->project();
        $team = $project->team();

        return $team === null
            ? WorkspaceAccess::administers($this->user(), $project->organization_id)
            : WorkspaceAccess::canAppointProjectManager($this->user(), $team);
    }

    private function crossOrganization(?Team $team): bool
    {
        if (WorkspaceAccess::isSuperAdmin($this->user()) && $this->has('is_cross_organization')) {
            return $this->boolean('is_cross_organization');
        }

        return (bool) $team?->is_cross_organization;
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $project = $this->project();

        if (! WorkspaceAccess::canChangeProjectKey($this->user(), $project)) {
            unset($data['key']);
        }

        if (! WorkspaceAccess::canPublishProject($this->user(), $project->organization_id)) {
            unset($data['is_public']);
        }

        if (! $this->appointsManager()) {
            unset($data['manager_id']);
        }

        if (! WorkspaceAccess::administers($this->user(), $project->organization_id)) {
            unset($data['program_id']);
        }

        if (! WorkspaceAccess::isSuperAdmin($this->user())) {
            unset($data['is_cross_organization']);
        }

        return data_get($data, $key, $default);
    }
}
