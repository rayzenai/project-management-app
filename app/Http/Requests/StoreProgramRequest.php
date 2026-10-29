<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Program;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A program belongs to one organization (the one a super-admin picks, or an
 * office admin's own) and has one manager: an active person of that
 * organization.
 */
class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canManagePrograms($this->user(), $this->organizationId());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'organization_id' => [
                Rule::requiredIf(fn (): bool => WorkspaceAccess::isSuperAdmin($this->user())),
                'nullable', 'integer', Rule::exists('organizations', 'id'),
            ],
            'manager_id' => ['required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $organizationId = $this->organizationId();
            $manager = Member::query()->find($this->integer('manager_id'));

            if ($manager === null || ! $manager->is_active || (int) $manager->organization_id !== $organizationId) {
                $validator->errors()->add('manager_id', "The program manager must be an active person of the program's organization.");
            }

            if (Program::query()->where('organization_id', $organizationId)->where('name', $this->input('name'))->exists()) {
                $validator->errors()->add('name', 'That name is already used in this organization.');
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

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $data['organization_id'] = $this->organizationId();

        return data_get($data, $key, $default);
    }
}
