<?php

namespace App\Http\Requests\Forms;

use App\Models\Member;
use App\Models\Project;
use App\Support\Forms\FormSchema;
use App\Support\WorkspaceAccess;
use Illuminate\Validation\Validator;

/**
 * Tasks from responses, on or off. On needs a project the person may send
 * the form's tasks to ({@see WorkspaceAccess::canSendFormTasksTo()}) and
 * someone a task there can be assigned to.
 */
class UpdateFormTasksRequest extends EditFormRequest
{
    private ?Project $project = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'project' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'assignee_id' => ['required_if:enabled,true', 'nullable', 'integer'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'title_question' => ['nullable', 'string', 'max:24'],
            'copy_questions' => ['nullable', 'array', 'max:100'],
            'copy_questions.*' => ['string', 'max:24'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('enabled') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $this->project = Project::query()->where('slug', (string) $this->input('project'))->first();

            if ($this->project === null || ! WorkspaceAccess::canSendFormTasksTo($this->user(), $this->form(), $this->project)) {
                $validator->errors()->add('project', 'Choose a project of '.($this->form()->project->organization->name ?? 'the organization').' that you can add tasks to.');

                return;
            }

            if (! Member::query()->assignableFor($this->project)->whereKey((int) $this->input('assignee_id'))->exists()) {
                $validator->errors()->add('assignee_id', 'Choose someone who can be given tasks in '.$this->project->title.'.');
            }
        });
    }

    /**
     * @return array{project_id: int, assignee_id: int, due_days: int|null, title_question: string|null, copy_questions: list<string>}|null
     */
    public function tasks(): ?array
    {
        if (! $this->boolean('enabled') || $this->project === null) {
            return null;
        }

        $questions = FormSchema::questions($this->form()->draft);
        $title = $this->input('title_question');

        return [
            'project_id' => $this->project->id,
            'assignee_id' => (int) $this->input('assignee_id'),
            'due_days' => $this->filled('due_days') ? (int) $this->input('due_days') : null,
            'title_question' => is_string($title) && isset($questions[$title]) && $questions[$title]['type'] === 'short_text' ? $title : null,
            'copy_questions' => array_values(array_filter(
                array_map('strval', (array) $this->input('copy_questions', [])),
                fn (string $id): bool => isset($questions[$id]) && $questions[$id]['type'] !== 'file',
            )),
        ];
    }
}
