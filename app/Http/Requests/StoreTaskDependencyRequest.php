<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Models\TaskDependency;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "This task cannot start until that one is finished." Only within one
 * project, so nobody who can see the task meets a dependency they could not
 * otherwise see, and never in a loop.
 */
class StoreTaskDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            && WorkspaceAccess::canViewProject($this->user(), $task->loadMissing('project')->project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'depends_on_task_id' => ['required', 'integer', Rule::exists('tasks', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Task $task */
            $task = $this->route('task');
            $dependsOnId = $this->integer('depends_on_task_id');
            $dependsOn = Task::query()->find($dependsOnId);

            $problem = match (true) {
                $dependsOnId === $task->id => 'A task cannot depend on itself.',
                $dependsOn === null || (int) $dependsOn->project_id !== (int) $task->project_id => 'Pick a task from the same project.',
                $task->dependencies()->whereKey($dependsOnId)->exists() => 'It already depends on that task.',
                TaskDependency::reaches($dependsOnId, $task->id) => 'That task already waits for this one, so this would go round in a circle.',
                default => null,
            };

            if ($problem !== null) {
                $validator->errors()->add('depends_on_task_id', $problem);
            }
        });
    }
}
