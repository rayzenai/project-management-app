<?php

namespace App\Http\Requests;

/**
 * Rename a private to-do, give it a date or take it away, start or stop
 * working on it (`doing`), tick it done or open it again. Its owner's only
 * (PersonalTodoRequest).
 */
class UpdatePersonalTodoRequest extends PersonalTodoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'doing' => ['sometimes', 'boolean'],
            'done' => ['sometimes', 'boolean'],
        ];
    }
}
