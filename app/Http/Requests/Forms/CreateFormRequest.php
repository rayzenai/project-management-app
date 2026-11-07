<?php

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Models\Project;
use App\Support\Forms\FormTemplates;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Make a form in a project the person may make forms in
 * ({@see WorkspaceAccess::canCreateForm()}); a copy of one uses the same
 * check for where it goes.
 */
class CreateFormRequest extends FormRequest
{
    private ?Project $project = null;

    public function authorize(): bool
    {
        return (bool) config('forms.enabled') && $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project' => ['required', 'string', 'max:255'],
            'title' => [$this->route('form') === null ? 'required' : 'nullable', 'string', 'max:'.Form::TITLE_MAX],
            'template' => ['nullable', Rule::in(FormTemplates::ids())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->project = Project::query()->where('slug', (string) $this->input('project'))->first();

            if ($this->project === null || ! WorkspaceAccess::canCreateForm($this->user(), $this->project)) {
                $validator->errors()->add('project', 'Choose a project you work on.');
            }
        });
    }

    public function project(): Project
    {
        abort_unless($this->project !== null, 422);

        return $this->project;
    }
}
