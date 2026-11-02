<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Program;
use App\Models\Team;
use App\Support\ProjectKeys;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A project belongs to one organization (the one a super-admin picks, or
 * the program's when they post one without; an office admin's own; for a
 * program manager their program's) and is
 * created with its team, led by the manager chosen here: an active person
 * of that organization. A program manager creates in their own programs
 * only, and without the administrators' fields (key, public).
 *
 * The team may start with more people (`member_ids`), each passing the
 * People tab's rule (WorkspaceAccess::canJoinTeam) for the team about to be
 * made: an active person of the project's organization, or anyone active on
 * a cross-organization team, which only a super-admin opens.
 */
class StoreProjectRequest extends FormRequest
{
    /** The posted program, looked up once: false when there is none. */
    private Program|false|null $postedProgram = null;

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
                Rule::requiredIf(fn (): bool => WorkspaceAccess::isSuperAdmin($this->user()) && ! $this->filled('program_id')),
                'nullable', 'integer', Rule::exists('organizations', 'id'),
            ],
            'manager_id' => ['required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
            'program_id' => [
                Rule::requiredIf(fn (): bool => WorkspaceAccess::createsProjectsOnlyInPrograms($this->user())),
                'nullable', 'integer', Rule::exists('programs', 'id'),
            ],
            'is_cross_organization' => ['nullable', 'boolean'],
            'member_ids' => ['nullable', 'array', 'max:500'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('members', 'id')->whereNull('deleted_at')],
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
            if (WorkspaceAccess::createsProjectsOnlyInPrograms($this->user())) {
                $program = $this->program();

                if ($program === null || ! WorkspaceAccess::canCreateProjectInProgram($this->user(), $program)) {
                    $validator->errors()->add('program_id', 'Choose one of your programs.');

                    return;
                }
            }

            // Only for those whose key is kept; anyone else's is dropped.
            if ($this->filled('key') && WorkspaceAccess::administers($this->user(), $this->organizationId())
                && ProjectKeys::isTaken((int) $this->organizationId(), (string) $this->input('key'))) {
                $validator->errors()->add('key', 'That key is already used in this organization.');
            }

            if ($this->filled('program_id') && WorkspaceAccess::administers($this->user(), $this->organizationId())
                && (int) $this->program()?->organization_id !== $this->organizationId()) {
                $validator->errors()->add('program_id', 'That program belongs to another organization.');
            }

            $manager = Member::query()->find($this->integer('manager_id'));

            if ($manager === null || ! $manager->is_active || (int) $manager->organization_id !== $this->organizationId()) {
                $validator->errors()->add('manager_id', "The manager must be an active person of the project's organization.");
            }

            if (! $this->membersMayJoin()) {
                $validator->errors()->add('member_ids', $this->crossOrganization()
                    ? 'Only active people can join the team.'
                    : "Only active people of the project's organization can join its team.");
            }
        });
    }

    /**
     * Whether everyone in `member_ids` may join the team about to be made,
     * asked of an unsaved team so the rule stays canJoinTeam's.
     */
    private function membersMayJoin(): bool
    {
        $ids = array_map(intval(...), (array) $this->input('member_ids', []));

        if ($ids === []) {
            return true;
        }

        $team = (new Team)->forceFill([
            'organization_id' => $this->organizationId(),
            'is_cross_organization' => $this->crossOrganization(),
        ]);

        $members = Member::query()->with('user')->whereKey($ids)->get();

        return $members->count() === count($ids)
            && $members->every(fn (Member $member): bool => $member->is_active && WorkspaceAccess::canJoinTeam($member, $team));
    }

    /** Opening the team to other organizations is a super-admin's call. */
    private function crossOrganization(): bool
    {
        return WorkspaceAccess::isSuperAdmin($this->user()) && $this->boolean('is_cross_organization');
    }

    public function organizationId(): ?int
    {
        if (WorkspaceAccess::isSuperAdmin($this->user())) {
            // A program's page fixes the program, and so the organization.
            return $this->integer('organization_id') ?: (int) $this->program()?->organization_id ?: null;
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

    private function program(): ?Program
    {
        if ($this->postedProgram === null) {
            $this->postedProgram = ($this->filled('program_id') ? Program::query()->find($this->integer('program_id')) : null) ?? false;
        }

        return $this->postedProgram === false ? null : $this->postedProgram;
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

        $data['is_cross_organization'] = $this->crossOrganization();

        return data_get($data, $key, $default);
    }
}
