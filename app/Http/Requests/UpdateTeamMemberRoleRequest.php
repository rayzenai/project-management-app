<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Choosing a project's manager (its team's leader) belongs to whoever
 * administers the team's organization (its office admin or a super-admin)
 * and to the manager of its project's program, never to a manager. The new
 * manager must be able to join the team, and a program manager's must be of
 * the team's organization (canAppointAsProjectManager). Making the manager a
 * member again, with no one in their place, is canRemoveManager's.
 */
class UpdateTeamMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');
        $member = $this->route('member');

        if (! $team instanceof Team || ! $member instanceof Member) {
            return false;
        }

        if ($this->input('role') === 'member' && $team->leaders()->whereKey($member->getKey())->exists()) {
            return WorkspaceAccess::canRemoveManager($this->user(), $team);
        }

        return WorkspaceAccess::canAppointProjectManager($this->user(), $team);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['member', 'leader'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Team $team */
            $team = $this->route('team');
            /** @var Member $member */
            $member = $this->route('member');

            if ($this->input('role') === 'leader' && (! $member->is_active || ! WorkspaceAccess::canAppointAsProjectManager($this->user(), $team, $member->loadMissing('user')))) {
                $validator->errors()->add('role', 'The manager must be an active person who can join this team.');
            }
        });
    }
}
