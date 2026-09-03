<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Task;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
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
            'member_id' => ['required', 'integer', 'exists:members,id', $this->inProjectScope()],
            'role' => ['nullable', 'string', 'max:128'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'personal_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'personal_due_at' => ['nullable', 'date'],
            'personal_status_note' => ['nullable', 'string'],
        ];
    }

    /**
     * The member must be assignable within the task's project — on one of the
     * project's teams, or any active member when the project has no teams.
     */
    private function inProjectScope(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $task = $this->route('task');

            if (! $task instanceof Task || ! $task->project) {
                return;
            }

            if (! Member::assignableFor($task->project)->whereKey($value)->exists()) {
                $fail("That person is not on this project's teams.");
            }
        };
    }
}
