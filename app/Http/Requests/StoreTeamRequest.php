<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Super-admins create a team in any organization and may make it
 * cross-organization; an office admin's teams always belong to their own
 * organization and are never cross-organization.
 */
class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canCreateTeam($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:16'],
            'organization_id' => [
                Rule::requiredIf(fn (): bool => WorkspaceAccess::isSuperAdmin($this->user())),
                'nullable', 'integer', Rule::exists('organizations', 'id'),
            ],
            'is_cross_organization' => ['nullable', 'boolean'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $team = new Team([
                'organization_id' => $this->organizationId(),
                'is_cross_organization' => $this->crossOrganization(),
            ]);

            $members = Member::query()->with('user')->whereIn('id', (array) $this->input('member_ids', []))->get();

            foreach ($members as $member) {
                if (! WorkspaceAccess::canJoinTeam($member, $team)) {
                    $validator->errors()->add('member_ids', "{$member->name} belongs to another organization.");

                    return;
                }
            }
        });
    }

    public function organizationId(): ?int
    {
        if (WorkspaceAccess::isSuperAdmin($this->user())) {
            return $this->integer('organization_id') ?: null;
        }

        return WorkspaceAccess::officeAdminOrganizationId($this->user());
    }

    public function crossOrganization(): bool
    {
        return WorkspaceAccess::isSuperAdmin($this->user()) && $this->boolean('is_cross_organization');
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $data['organization_id'] = $this->organizationId();
        $data['is_cross_organization'] = $this->crossOrganization();

        return data_get($data, $key, $default);
    }
}
