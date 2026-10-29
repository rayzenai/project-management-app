<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

class LinkRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && WorkspaceAccess::canManageProjectRepositories($this->user(), $project);
    }

    /**
     * The repository as the forge names it, `owner/name`; `.` and `..` are
     * never names (they would walk the API path).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', 'not_regex:#(^|/)\.{1,2}(/|$)#'],
        ];
    }
}
