<?php

use App\Models\ProjectActivity;
use App\Models\Task;

it('records a restored activity when a trashed task is restored', function () {
    $task = Task::factory()->create();
    $task->delete();

    $task->restore();

    expect(ProjectActivity::where('action', ProjectActivity::ACTION_RESTORED)
        ->where('subject_type', 'task')
        ->where('subject_id', $task->id)
        ->exists())->toBeTrue();
});
