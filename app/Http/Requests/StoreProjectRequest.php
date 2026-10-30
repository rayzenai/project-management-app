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
 * A project belongs to one organization (the one a super-admin picks, an
 * office admin's own, or for a program manager their program's) and is
 * created with its team, led by the manager chosen here: an active person
 * of that organization. A program manager creates in their own programs
 * only, and without the administrators' fields (key, public).
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
            'program_id' => [
                Rule::requiredIf(fn (): bool => ! $this->isAdministrator()),
                'nullable', 'integer', Rule::exists('programs', 'id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['program_id.required' => 'Choose one of your programs.'];
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

            // A program manager's project lands in one of their programs.
            if (! $this->isAdministrator()) {
                $program = $this->program();

                if ($program === null || ! WorkspaceAccess::canCreateProjectInProgram($this->user(), $program)) {
                    $validator->errors()->add('program_id', 'Choose one of your programs.');

                    return;
                }
            }

            // Only for those whose key is kept; anyone else's is dropped.
            if ($this->filled('key') && $this->isAdministrator()
                && ProjectKeys::isTaken((int) $this->organizationId(), (string) $this->input('key'))) {
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

        $officeAdminOf = WorkspaceAccess::officeAdminOrganizationId($this->user());

        if ($officeAdminOf !== null) {
            return $officeAdminOf;
        }

        // A program manager's: the organization of their program, and only
        // of one of theirs.
        $program = $this->program();

        return $program !== null && WorkspaceAccess::canCreateProjectInProgram($this->user(), $program)
            ? (int) $program->organization_id
            : null;
    }

    /**
     * A super-admin or an office admin, who create in any organization they
     * administer; anyone else allowed here is a program manager.
     */
    private function isAdministrator(): bool
    {
        return WorkspaceAccess::isSuperAdmin($this->user()) || WorkspaceAccess::officeAdminOrganizationId($this->user()) !== null;
    }

    private function program(): ?Program
    {
        return $this->filled('program_id') ? Program::query()->find($this->integer('program_id')) : null;
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $data['organization_id'] = $this->organizationId();

        // Public opens it to the whole organization: its administrators' call.
        if (! $this->boolean('is_public') || ! WorkspaceAccess::canPublishProject($this->user(), $this->organizationId())) {
            unset($data['is_public']);
        }

        // Choosing the key is the administrators' call; the title suggests one
        // otherwise. A program manager's program_id passed the checks above.
        if (! WorkspaceAccess::administers($this->user(), $this->organizationId())) {
            unset($data['key']);
        }

        return data_get($data, $key, $default);
    }
}
