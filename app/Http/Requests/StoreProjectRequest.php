<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A project belongs to one organization: the one a super-admin picks, an
 * office admin's own, or, for a team leader, the organization of the teams
 * they give access to. Every team on it must belong to that organization.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canCreateProject($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'title_np' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_np' => ['nullable', 'string'],
            'is_public' => ['nullable', 'boolean'],
            'organization_id' => [
                Rule::requiredIf(fn (): bool => WorkspaceAccess::isSuperAdmin($this->user())),
                'nullable', 'integer', Rule::exists('organizations', 'id'),
            ],
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $teamIds = $this->teamIds();
            $organizationId = $this->organizationId();

            if (! $this->isPublic() && $teamIds === []) {
                $validator->errors()->add('team_ids', 'Select at least one team, or make the project public.');

                return;
            }

            $teams = Team::query()->whereIn('id', $teamIds)->get();

            if ($teams->contains(fn (Team $team): bool => (int) $team->organization_id !== $organizationId)) {
                $validator->errors()->add('team_ids', "Teams must belong to the project's organization.");

                return;
            }

            if (! WorkspaceAccess::administers($this->user(), $organizationId)) {
                $led = WorkspaceAccess::ledTeamIds($this->user());

                if (array_diff($teamIds, $led) !== []) {
                    $validator->errors()->add('team_ids', 'You can only grant access to teams you lead.');
                }
            }
        });
    }

    public function organizationId(): ?int
    {
        if (WorkspaceAccess::isSuperAdmin($this->user())) {
            return $this->integer('organization_id') ?: null;
        }

        $officeAdminOf = WorkspaceAccess::officeAdminOrganizationId($this->user());

        if ($officeAdminOf !== null) {
            return $officeAdminOf;
        }

        $teamOrganization = Team::query()->whereIn('id', $this->teamIds())->value('organization_id');

        return $teamOrganization === null ? null : (int) $teamOrganization;
    }

    /**
     * Public means open to the whole organization, so only its office admin
     * or a super-admin may publish.
     */
    private function isPublic(): bool
    {
        return $this->boolean('is_public')
            && WorkspaceAccess::canPublishProject($this->user(), $this->organizationId());
    }

    /**
     * @return list<int>
     */
    private function teamIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('team_ids', [])));
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $data['organization_id'] = $this->organizationId();

        if (! $this->isPublic()) {
            unset($data['is_public']);
        }

        return data_get($data, $key, $default);
    }
}
