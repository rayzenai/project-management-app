<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Task;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deletes a task, or restores it from the trash: whoever runs its project,
 * or the person who created it (WorkspaceAccess::canDeleteTask).
 */
class DeleteTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $task = $this->route('task');

        return $project instanceof Project
            && $task instanceof Task
            && WorkspaceAccess::canDeleteTask($this->user(), $task, $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
