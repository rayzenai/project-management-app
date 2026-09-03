<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Attaches a member to a team. Either `member_id` (attach an existing member)
 * or `name` (create a brand-new member, optionally with a login) is required.
 */
class StoreTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        if (! $team instanceof Team || ! WorkspaceAccess::canManageRosterOf($this->user(), $team)) {
            return false;
        }

        $memberId = (int) $this->input('member_id');

        if ($memberId > 0 && ! WorkspaceAccess::isSuperAdmin($this->user())) {
            $member = Member::find($memberId);

            // Unknown id: let the `exists` validation rule report it.
            if ($member !== null && $member->user_id !== null) {
                $ledTeamIds = WorkspaceAccess::ledTeamIds($this->user());

                if (! $member->teams()->whereIn('teams.id', $ledTeamIds)->exists()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required_without:name', 'nullable', 'integer', 'exists:members,id'],
            'name' => ['required_without:member_id', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'required_with:password', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
