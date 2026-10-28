<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        if (! $member instanceof Member || ! WorkspaceAccess::canManageMember($this->user(), $member)) {
            return false;
        }

        // Organization, office-admin status and bulk team reassignment are
        // super-admin decisions; office admins use the team rosters.
        foreach (['team_ids', 'organization_id', 'is_office_admin'] as $superAdminOnly) {
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
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * Only a super-admin may hand out a super-admin's email (the Gate matches
     * on email). Moving someone to another organization would leave them on
     * teams they can no longer join, so it waits until they are off those
     * teams.
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

            if ($this->has('team_ids')) {
                $moved = $member->replicate();
                $moved->organization_id = $this->has('organization_id') ? $this->integer('organization_id') : $member->organization_id;

                foreach (Team::query()->whereIn('id', (array) $this->input('team_ids', []))->get() as $team) {
                    if (! WorkspaceAccess::canJoinTeam($moved, $team)) {
                        $validator->errors()->add('team_ids', "{$team->name} belongs to another organization.");

                        return;
                    }
                }
            }

            if (! $this->has('organization_id')) {
                return;
            }

            $organizationId = $this->integer('organization_id');

            if ($organizationId === (int) $member->organization_id) {
                return;
            }

            $stranded = $member->teams()
                ->where('teams.is_cross_organization', false)
                ->where('teams.organization_id', '!=', $organizationId)
                ->when($this->has('team_ids'), fn ($q) => $q->whereIn('teams.id', (array) $this->input('team_ids', [])))
                ->pluck('teams.name');

            if ($stranded->isNotEmpty()) {
                $validator->errors()->add('organization_id', 'Take them off '.$stranded->implode(', ').' first.');
            }
        });
    }
}
