<?php

namespace App\Http\Requests;

use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canCreateProject($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'title_np' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_np' => ['nullable', 'string'],
            'is_public' => ['nullable', 'boolean'],
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $teamIds = array_map('intval', (array) $this->input('team_ids', []));
            $isPublic = WorkspaceAccess::isSuperAdmin($this->user()) && $this->boolean('is_public');

            if (! $isPublic && $teamIds === []) {
                $validator->errors()->add('team_ids', 'Select at least one team, or make the project public.');
            }

            if (! WorkspaceAccess::isSuperAdmin($this->user())) {
                $led = WorkspaceAccess::ledTeamIds($this->user());
                foreach ($teamIds as $id) {
                    if (! in_array($id, $led, true)) {
                        $validator->errors()->add('team_ids', 'You can only grant access to teams you lead.');
                        break;
                    }
                }
            }
        });
    }

    /**
     * Strip is_public for non-super-admins so only they can publish.
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (! WorkspaceAccess::isSuperAdmin($this->user())) {
            unset($data['is_public']);
        }

        return data_get($data, $key, $default);
    }
}
