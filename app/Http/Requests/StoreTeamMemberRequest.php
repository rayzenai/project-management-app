<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Puts an existing person on a team: the leader found them by searching
 * (TeamCandidatesQuery). New people are created by an office admin or a
 * super-admin through the members endpoint, never here.
 */
class StoreTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && WorkspaceAccess::canManageRosterOf($this->user(), $team);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Team $team */
            $team = $this->route('team');
            $member = Member::query()->with('user')->find($this->integer('member_id'));

            if ($member !== null && ! WorkspaceAccess::canJoinTeam($member, $team)) {
                $validator->errors()->add('member_id', "Only people from this team's organization can join it.");
            }
        });
    }
}
