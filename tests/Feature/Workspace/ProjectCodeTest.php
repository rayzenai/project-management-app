<?php

use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;

it('builds a short code from the words in the title', function (string $title, string $expected) {
    expect((new Project(['title' => $title]))->code)->toBe($expected);
})->with([
    'plain words' => ['Digital Nepal Rollout', 'DNR'],
    // "100-Day" is one word, so it contributes a single character.
    'hyphenated word stays one word' => ['Government 100-Day Plan', 'G1P'],
    'lowercase is upcased' => ['ward service upgrade', 'WSU'],
    'capped at four characters' => ['One Two Three Four Five Six', 'OTTF'],
    'punctuation is skipped' => ['  Roads &  Bridges ', 'RB'],
    'single word' => ['Procurement', 'P'],
]);

it('falls back to the id when the title yields no letters or digits', function () {
    $project = Project::factory()->create(['title' => '—']);

    expect($project->code)->toBe('P'.$project->id);
});

it('exposes the code on the task payload so a card can render CODE-123', function () {
    $project = Project::factory()->create(['title' => 'Digital Nepal Rollout']);
    $task = Task::factory()->for($project)->create(['item_number' => 106]);

    $payload = (new TaskResource($task->load('project')))->resolve();

    expect($payload['project']['code'])->toBe('DNR')
        ->and($payload['item_number'])->toBe(106);
});

it('omits the project block when the relation is not loaded', function () {
    // The code is only ever read off a loaded relation, so nothing here can
    // trigger a per-task query on a list of hundreds.
    $task = Task::factory()->create();

    expect((new TaskResource($task))->resolve())->not->toHaveKey('project');
});
