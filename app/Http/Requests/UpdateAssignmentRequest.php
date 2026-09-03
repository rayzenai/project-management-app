<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'nullable', 'string', 'max:128'],
            'priority' => ['sometimes', 'nullable', 'in:low,medium,high,urgent'],
            'is_focused' => ['sometimes', 'boolean'],
            'snoozed_until' => ['sometimes', 'nullable', 'date'],
            'personal_progress' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'personal_due_at' => ['sometimes', 'nullable', 'date'],
            'personal_status_note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
