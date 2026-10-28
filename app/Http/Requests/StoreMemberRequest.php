<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * People are created by whoever administers their organization: a
 * super-admin (who picks the organization and may make them its office
 * admin) or that organization's office admin. Team leaders add existing
 * people to their teams instead.
 */
class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canCreateMember($this->user(), $this->organizationId());
    }

    /**
     * Every member gets a login (host users row) that signs in with this
     * email. The web form always sends the password it showed the admin; an
     * API caller may leave it out and one is generated. Either way the member
     * is emailed both (SendLoginDetailsService).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'title' => ['nullable', 'string', 'max:255'],
            'organization_id' => [
                Rule::requiredIf(fn (): bool => WorkspaceAccess::isSuperAdmin($this->user())),
                'nullable', 'integer', Rule::exists('organizations', 'id'),
            ],
            'is_office_admin' => ['nullable', 'boolean'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! WorkspaceAccess::isSuperAdmin($this->user()) && WorkspaceAccess::isSuperAdminEmail($this->input('email'))) {
                $validator->errors()->add('email', 'This email is reserved for a super-admin.');

                return;
            }

            $organizationId = $this->organizationId();

            foreach (Team::query()->whereIn('id', (array) $this->input('team_ids', []))->get() as $team) {
                $sameOrganization = (int) $team->organization_id === $organizationId;
                $openTeam = $team->is_cross_organization && WorkspaceAccess::canManageRosterOf($this->user(), $team);

                if (! $sameOrganization && ! $openTeam) {
                    $validator->errors()->add('team_ids', "{$team->name} belongs to another organization.");

                    return;
                }
            }
        });
    }

    /**
     * Super-admins choose; an office admin's people always join their own
     * organization.
     */
    public function organizationId(): ?int
    {
        if (WorkspaceAccess::isSuperAdmin($this->user())) {
            return $this->integer('organization_id') ?: null;
        }

        return WorkspaceAccess::officeAdminOrganizationId($this->user());
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $data['organization_id'] = $this->organizationId();
        $data['is_office_admin'] = WorkspaceAccess::isSuperAdmin($this->user()) && $this->boolean('is_office_admin');

        return data_get($data, $key, $default);
    }
}
