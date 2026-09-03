<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'assignee_member_ids' => ['nullable', 'array'],
            'assignee_member_ids.*' => ['integer', 'exists:members,id'],
            'deadline_at' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
        ];
    }
}
