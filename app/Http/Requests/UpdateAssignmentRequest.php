<?php

namespace App\Http\Requests;

use App\Models\ProjectAssignment;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    /** What only the person on the assignment changes. */
    public const PERSONAL_FIELDS = ['is_focused', 'snoozed_until', 'personal_progress', 'personal_due_at', 'personal_status_note'];

    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        if (! $assignment instanceof ProjectAssignment || ! WorkspaceAccess::canUpdateAssignment($this->user(), $assignment)) {
            return false;
        }

        return ! $this->hasAny(self::PERSONAL_FIELDS)
            || WorkspaceAccess::ownsAssignment($this->user(), $assignment);
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
