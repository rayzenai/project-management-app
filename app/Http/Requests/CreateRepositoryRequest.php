<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A new forge repository for the project: named after the project's key
 * (`tp`, or `tp-` and more), in the forge's own name rules.
 */
class CreateRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && WorkspaceAccess::canManageProjectRepositories($this->user(), $project);
    }

    /**
     * Lowercase letters and digits, a single `.`, `_` or `-` between them;
     * not one of the names the forge keeps for its own pages.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', 'not_regex:/\.(git|wiki|rss|atom)$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Use lowercase letters and digits, with a single ., _ or - between them.',
            'name.not_regex' => "The name can't end in .git, .wiki, .rss or .atom.",
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $project = $this->route('project');

            if ($validator->errors()->isNotEmpty() || ! $project instanceof Project) {
                return;
            }

            $key = strtolower($project->key);
            $name = (string) $this->input('name');

            if ($name !== $key && ! str_starts_with($name, $key.'-')) {
                $validator->errors()->add('name', "Start the name with {$key}, such as {$key}-api.");
            }
        });
    }
}
