<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksMemberRoles;
use App\Http\Requests\Concerns\SetsMemberStructure;
use App\Models\Member;
use App\Models\Program;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMemberRequest extends FormRequest
{
    use ChecksMemberRoles;
    use SetsMemberStructure;

    public function authorize(): bool
    {
        $member = $this->route('member');

        if (! $member instanceof Member || ! WorkspaceAccess::canManageMember($this->user(), $member)) {
            return false;
        }

        // Organization and organizational-admin status are super-admin
        // decisions. The role fields are open to whoever manages the person,
        // within the organizations they administer (checkRoles).
        foreach (['organization_id', 'is_office_admin'] as $superAdminOnly) {
            if ($this->has($superAdminOnly) && ! WorkspaceAccess::isSuperAdmin($this->user())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Name/email changes sync to the linked login; a password value resets it
     * (or provisions a login for a pre-login-era member).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $member = $this->route('member');
        $linkedUserId = $member instanceof Member ? $member->user_id : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($linkedUserId)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'organization_id' => ['sometimes', 'required', 'integer', Rule::exists('organizations', 'id')],
            'is_office_admin' => ['sometimes', 'boolean'],
            ...$this->roleRules(),
            ...$this->structureRules(),
        ];
    }

    /**
     * The person's organization after the save (a super-admin may move them).
     */
    protected function structureOrganizationId(): ?int
    {
        if ($this->has('organization_id')) {
            return $this->integer('organization_id') ?: null;
        }

        $member = $this->route('member');

        return $member instanceof Member && $member->organization_id !== null ? (int) $member->organization_id : null;
    }

    /**
     * Only a super-admin may hand out a super-admin's email (the Gate matches
     * on email). Moving someone to another organization would leave them on
     * teams they can no longer join, or managing projects or programs of an
     * organization they left, so it waits until they are off those teams and
     * someone else manages those projects and programs.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Member $member */
            $member = $this->route('member');

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->has('email') && ! WorkspaceAccess::isSuperAdmin($this->user())
                && WorkspaceAccess::isSuperAdminEmail($this->input('email'))
                && mb_strtolower((string) $this->input('email')) !== mb_strtolower((string) $member->email)) {
                $validator->errors()->add('email', 'This email is reserved for a super-admin.');

                return;
            }

            // Roles are checked against the person as they will be.
            $moved = $member->replicate();
            $moved->organization_id = $this->has('organization_id') ? $this->integer('organization_id') : $member->organization_id;
            $this->checkRoles($validator, $moved, $member);

            if ($validator->errors()->isNotEmpty() || ! $this->has('organization_id')) {
                return;
            }

            $organizationId = $this->integer('organization_id');

            if ($organizationId === (int) $member->organization_id) {
                return;
            }

            $teams = $member->teams()
                ->where('teams.is_cross_organization', false)
                ->where('teams.organization_id', '!=', $organizationId)
                ->with('projects.program:id,name')
                ->orderBy('teams.name')
                ->get();
            $names = fn (Collection $named): string => $named->map(fn (Team $team): string => $team->project()?->label() ?? $team->name)->implode(', ');

            // team_ids never takes a manager off their team, so its manager
            // is chosen first.
            $managed = $teams->filter(fn (Team $team): bool => ($team->pivot->role ?? 'member') === 'leader');
            $stranded = $teams->diff($managed)
                ->when($this->has('team_ids'), fn ($c) => $c->whereIn('id', (array) $this->input('team_ids', [])));

            if ($stranded->isNotEmpty()) {
                $validator->errors()->add('organization_id', 'Take them off '.$names($stranded).' first.');
            }

            if ($managed->isNotEmpty()) {
                $validator->errors()->add('organization_id', 'Choose another manager for '.$names($managed).' first.');
            }

            $programs = Program::query()
                ->where('manager_id', $member->getKey())
                ->where('organization_id', $member->organization_id)
                ->orderBy('name')
                ->pluck('name');

            if ($programs->isNotEmpty()) {
                $validator->errors()->add('organization_id', 'Choose another manager for the '.$programs->implode(', ').' '.Str::plural('program', $programs->count()).' first.');
            }
        });
    }
}
