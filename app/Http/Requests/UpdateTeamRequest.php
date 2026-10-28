<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The team's organization never changes. Only a super-admin may switch
 * cross-organization on or off (anyone else's value is dropped), and it
 * cannot be switched off while people from other organizations are on it.
 */
class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && WorkspaceAccess::canEditTeam($this->user(), $team);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'color' => ['sometimes', 'nullable', 'string', 'max:16'],
            'is_cross_organization' => ['sometimes', 'boolean'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Team $team */
            $team = $this->route('team');

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $after = $team->replicate();
            $after->is_cross_organization = $this->crossOrganization($team);

            $memberIds = $this->has('member_ids')
                ? (array) $this->input('member_ids', [])
                : $team->members()->pluck('members.id')->all();

            foreach (Member::query()->with('user')->whereIn('id', $memberIds)->get() as $member) {
                if (! WorkspaceAccess::canJoinTeam($member, $after)) {
                    $field = $this->has('member_ids') ? 'member_ids' : 'is_cross_organization';
                    $validator->errors()->add($field, "{$member->name} belongs to another organization. Take them off the team first.");

                    return;
                }
            }
        });
    }

    private function crossOrganization(Team $team): bool
    {
        if (WorkspaceAccess::isSuperAdmin($this->user()) && $this->has('is_cross_organization')) {
            return $this->boolean('is_cross_organization');
        }

        return $team->is_cross_organization;
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (! WorkspaceAccess::isSuperAdmin($this->user())) {
            unset($data['is_cross_organization']);
        }

        return data_get($data, $key, $default);
    }
}
