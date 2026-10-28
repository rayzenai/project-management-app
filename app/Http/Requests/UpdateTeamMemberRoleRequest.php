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
 * administers the team's organization (its office admin or a super-admin),
 * never to a manager. The new manager must be able to join the team.
 */
class UpdateTeamMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');
        $member = $this->route('member');

        return $team instanceof Team
            && $member instanceof Member
            && WorkspaceAccess::canEditTeam($this->user(), $team);
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

            if ($this->input('role') === 'leader' && (! $member->is_active || ! WorkspaceAccess::canJoinTeam($member->loadMissing('user'), $team))) {
                $validator->errors()->add('role', 'The manager must be an active person who can join this team.');
            }
        });
    }
}
