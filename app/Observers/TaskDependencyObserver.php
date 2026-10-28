<?php

namespace App\Observers;

use App\Models\ProjectActivity;
use App\Models\TaskDependency;
use App\Services\ProjectActivityRecorder;

class TaskDependencyObserver
{
    public function created(TaskDependency $dependency): void
    {
        ProjectActivityRecorder::record(
            taskId: $dependency->task_id,
            subject: $dependency,
            action: ProjectActivity::ACTION_CREATED,
            description: 'Now depends on "'.ProjectActivityRecorder::truncate($dependency->dependsOn?->title).'"',
        );
    }

    public function deleted(TaskDependency $dependency): void
    {
        ProjectActivityRecorder::record(
            taskId: $dependency->task_id,
            subject: $dependency,
            action: ProjectActivity::ACTION_DELETED,
            description: 'No longer depends on "'.ProjectActivityRecorder::truncate($dependency->dependsOn?->title).'"',
        );
    }
}
