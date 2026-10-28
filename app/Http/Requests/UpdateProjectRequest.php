<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Project $project */
            $project = $this->route('project');

            if ($validator->errors()->isNotEmpty() || ! $this->has('team_ids')) {
                return;
            }

            $teamIds = array_values(array_map('intval', (array) $this->input('team_ids', [])));
            $foreign = Team::query()->whereIn('id', $teamIds)->where('organization_id', '!=', $project->organization_id)->exists();

            if ($foreign) {
                $validator->errors()->add('team_ids', "Teams must belong to the project's organization.");

                return;
            }

            if (! WorkspaceAccess::administers($this->user(), $project->organization_id)
                && array_diff($teamIds, WorkspaceAccess::ledTeamIds($this->user())) !== []) {
                $validator->errors()->add('team_ids', 'You can only grant access to teams you lead.');
            }
        });
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        /** @var Project $project */
        $project = $this->route('project');

        if (! WorkspaceAccess::canPublishProject($this->user(), $project->organization_id)) {
            unset($data['is_public']);
        }

        return data_get($data, $key, $default);
    }
}
