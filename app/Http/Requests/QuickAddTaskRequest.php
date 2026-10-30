<?php

namespace App\Http\Requests;

use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuickAddTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * Project-scope checks for the assignees happen in the service, because a
     * `#project` token in the title can override `project_id`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Without one, only an organizational admin whose organization
            // has no project yet may ask to start "General" for the task.
            'project_id' => [Rule::requiredIf(fn (): bool => ! $this->startsGeneral()), 'nullable', 'integer', 'exists:projects,id'],
            'start_general' => ['nullable', 'boolean'],
            'title' => ['required', 'string', 'max:255'],
            'assignee_member_ids' => ['nullable', 'array'],
            'assignee_member_ids.*' => ['integer', 'exists:members,id'],
            'deadline_at' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'status' => ['nullable', 'string', Rule::in(array_keys((array) config('project-management.statuses')))],
            'description' => ['nullable', 'string', 'max:5000'],
            // Tasks the new one waits for; ones outside its project are ignored.
            'depends_on_task_ids' => ['nullable', 'array'],
            'depends_on_task_ids.*' => ['integer', Rule::exists('tasks', 'id')->whereNull('deleted_at')],
            // Web only: land on the new task's project board instead of back
            // where you were. Ignored by the API surface.
            'redirect_to_project' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['project_id.required' => 'Pick a project.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->startsGeneral() && ! WorkspaceAccess::canStartGeneralProject($this->user())) {
                $validator->errors()->add('project_id', 'Pick a project.');
            }
        });
    }

    /**
     * Whether the task asks for "General" instead of a project.
     */
    public function startsGeneral(): bool
    {
        return $this->boolean('start_general') && ! $this->filled('project_id');
    }
}
