<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

class DisableGitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('organization') instanceof Organization
            && WorkspaceAccess::canManageGit($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
