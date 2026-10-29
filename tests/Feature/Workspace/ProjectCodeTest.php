<?php

use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('builds a short code from the words in the title', function (string $title, string $expected) {
    expect((new Project(['title' => $title]))->code)->toBe($expected);
})->with([
    'plain words' => ['Digital Nepal Rollout', 'DNR'],
    // "100-Day" has no ASCII letter before the hyphen run, so it adds its first letter only (D).
    'hyphenated word stays one word' => ['Government 100-Day Plan', 'GDP'],
    'lowercase is upcased' => ['ward service upgrade', 'WSU'],
    'capped at four characters' => ['One Two Three Four Five Six', 'OTTF'],
    'punctuation is skipped' => ['  Roads &  Bridges ', 'RB'],
    'single word' => ['Procurement', 'P'],
]);

it('falls back to P when the title yields no ASCII letters', function () {
    $project = Project::factory()->create(['title' => '—']);

    expect($project->code)->toBe('P');
});

it('exposes the code on the task payload so a card can render CODE-123', function () {
    $project = Project::factory()->create(['title' => 'Digital Nepal Rollout']);
    $task = Task::factory()->for($project)->create(['item_number' => 106]);

    $payload = (new TaskResource($task->load('project')))->resolve();

    expect($payload['project']['code'])->toBe('DNR')
        ->and($payload['number'])->toBe($task->number)
        ->and($payload['item_number'])->toBe(106);
});

it('omits the project block when the relation is not loaded', function () {
    // The code is only ever read off a loaded relation, so nothing here can
    // trigger a per-task query on a list of hundreds.
    $task = Task::factory()->create();

    expect((new TaskResource($task))->resolve())->not->toHaveKey('project');
});

it('never guesses the code of a saved project loaded without its key', function () {
    $project = Project::factory()->create(['title' => 'Digital Nepal Rollout', 'key' => 'EGP']);

    expect(Project::query()->findOrFail($project->id)->code)->toBe('EGP')
        ->and(fn () => Project::query()->select(['id', 'title'])->findOrFail($project->id)->code)->toThrow(TypeError::class);
});

it('gives the code on the projects of My Workspace', function () {
    $user = User::factory()->withMember()->create();
    Project::factory()->public()->create(['title' => 'Digital Nepal Rollout', 'key' => 'EGP']);

    $this->actingAs($user)->get('/workspace/my')
        ->assertInertia(fn (Assert $page) => $page->where('projects.0.code', 'EGP'));
});
