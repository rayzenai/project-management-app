<?php

namespace App\Http\Requests;

/**
 * Move a private to-do into a project, as a task. Its owner's only
 * (PersonalTodoRequest); whether the project takes it is quick add's answer
 * (QuickAddDispatcher::fileTitle).
 */
class MovePersonalTodoRequest extends PersonalTodoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['project_id.required' => 'Pick a project.'];
    }
}
