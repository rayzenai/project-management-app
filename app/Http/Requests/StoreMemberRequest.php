<?php

namespace App\Http\Requests;

use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $teamIds = array_values(array_map('intval', (array) $this->input('team_ids', [])));

        return WorkspaceAccess::canCreateMemberForTeams($this->user(), $teamIds);
    }

    /**
     * A password provisions a login (host users row) for the member, in which
     * case the email it signs in with is mandatory. Without a password the
     * member is assignable but cannot sign in — upgradeable later via edit.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_with:password', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'title' => ['nullable', 'string', 'max:255'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
        ];
    }
}
