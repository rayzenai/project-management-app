<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choosing a team's leaders belongs to whoever administers the team's
 * organization (its office admin or a super-admin), not to the leaders.
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
}
