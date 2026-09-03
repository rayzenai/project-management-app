<?php

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;

it('builds a stable data payload via the database channel', function () {
    $task = Task::factory()->create(['title' => 'File the report']);
    $user = User::factory()->create();

    $n = new TaskAssigned($task, actorName: 'Kiran');

    expect($n->via($user))->toBe(['database']);

    $data = $n->toArray($user);
    expect($data['kind'])->toBe('task_assigned')
        ->and($data['task']['title'])->toBe('File the report')
        ->and($data['task']['project_slug'])->toBe($task->project->slug)
        ->and($data['url'])->toContain($task->slug)
        ->and($data['actor']['name'])->toBe('Kiran');
});
