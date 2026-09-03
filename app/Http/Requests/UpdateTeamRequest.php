<?php

namespace App\Http\Requests;

use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::isSuperAdmin($this->user());
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
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', 'exists:members,id'],
        ];
    }
}
