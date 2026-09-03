<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');
        $member = $this->route('member');

        return $team instanceof Team
            && $member instanceof Member
            && WorkspaceAccess::canManageRosterOf($this->user(), $team)
            && WorkspaceAccess::canManageMember($this->user(), $member);
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
