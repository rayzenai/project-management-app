<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Program;
use App\Support\ProjectKeys;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A project belongs to one organization (the one a super-admin picks, or an
 * office admin's own) and is created with its team, led by the manager
 * chosen here: an active person of that organization.
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
            'key' => ['nullable', 'string', 'regex:/^[A-Z]{1,10}$/'],
            'is_public' => ['nullable', 'boolean'],
            'organization_id' => [
                Rule::requiredIf(fn (): bool => WorkspaceAccess::isSuperAdmin($this->user())),
                'nullable', 'integer', Rule::exists('organizations', 'id'),
            ],
            'manager_id' => ['required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
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

            if ($this->filled('key') && ProjectKeys::isTaken((int) $this->organizationId(), (string) $this->input('key'))) {
                $validator->errors()->add('key', 'That key is already used in this organization.');
            }

            if ($this->filled('program_id') && WorkspaceAccess::administers($this->user(), $this->organizationId())
                && (int) Program::query()->whereKey($this->integer('program_id'))->value('organization_id') !== $this->organizationId()) {
                $validator->errors()->add('program_id', 'That program belongs to another organization.');
            }

            $manager = Member::query()->find($this->integer('manager_id'));

            if ($manager === null || ! $manager->is_active || (int) $manager->organization_id !== $this->organizationId()) {
                $validator->errors()->add('manager_id', "The manager must be an active person of the project's organization.");
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

        // Public opens it to the whole organization: its administrators' call.
        if (! $this->boolean('is_public') || ! WorkspaceAccess::canPublishProject($this->user(), $this->organizationId())) {
            unset($data['is_public']);
        }

        // Choosing the key is the administrators' call; the title suggests one otherwise.
        if (! WorkspaceAccess::administers($this->user(), $this->organizationId())) {
            unset($data['key']);
            unset($data['program_id']);
        }

        return data_get($data, $key, $default);
    }
}
