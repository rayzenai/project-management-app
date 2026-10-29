<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

class UnlinkRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && WorkspaceAccess::canManageProjectRepositories($this->user(), $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
