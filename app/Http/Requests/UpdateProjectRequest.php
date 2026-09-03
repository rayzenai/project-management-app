<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && WorkspaceAccess::canManageProjectAccess($this->user(), $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'title_np' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'description_np' => ['sometimes', 'nullable', 'string'],
            'is_public' => ['sometimes', 'nullable', 'boolean'],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (WorkspaceAccess::isSuperAdmin($this->user()) || ! $this->has('team_ids')) {
                return;
            }

            $led = WorkspaceAccess::ledTeamIds($this->user());
            foreach (array_map('intval', (array) $this->input('team_ids', [])) as $id) {
                if (! in_array($id, $led, true)) {
                    $validator->errors()->add('team_ids', 'You can only grant access to teams you lead.');
                    break;
                }
            }
        });
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (! WorkspaceAccess::isSuperAdmin($this->user())) {
            unset($data['is_public']);
        }

        return data_get($data, $key, $default);
    }
}
