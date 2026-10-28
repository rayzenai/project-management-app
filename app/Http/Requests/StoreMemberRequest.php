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
     * Every member gets a login (host users row) that signs in with this
     * email. The web form always sends the password it showed the admin; an
     * API caller may leave it out and one is generated. Either way the member
     * is emailed both (SendLoginDetailsService).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'title' => ['nullable', 'string', 'max:255'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
        ];
    }
}
