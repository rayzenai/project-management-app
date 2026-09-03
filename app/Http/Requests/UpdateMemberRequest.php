<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        if (! $member instanceof Member || ! WorkspaceAccess::canManageMember($this->user(), $member)) {
            return false;
        }

        // Team membership for leaders is managed through the team-scoped roster
        // endpoints; only super-admins may bulk-reassign teams via member update.
        if ($this->has('team_ids') && ! WorkspaceAccess::isSuperAdmin($this->user())) {
            return false;
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
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($linkedUserId)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
        ];
    }
}
