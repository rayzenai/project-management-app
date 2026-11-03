<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Queries\MyWorkQuery;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * The app's My work: the web page's payload as JSON, without the web's
 * organization switch.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-04 10:00:00');

    $this->project = Project::factory()->create(['key' => 'MW']);
    $this->user = User::factory()->create();
    $this->member = Member::factory()->linkedTo($this->user)->create(['organization_id' => $this->project->organization_id]);
    $this->project->ensureTeam()->members()->attach($this->member->id);

    $open = Task::factory()->create(['project_id' => $this->project->id, 'status' => 'in_progress', 'deadline_at' => '2026-11-02']);
    ProjectAssignment::create(['task_id' => $open->id, 'member_id' => $this->member->id]);
    TaskComment::factory()->create(['task_id' => $open->id, 'created_at' => '2026-11-03 09:00:00']);
    Subtask::create(['task_id' => $open->id, 'user_id' => $this->user->id, 'body' => 'Call', 'due_at' => '2026-11-05']);
    $done = Task::factory()->create(['project_id' => $this->project->id, 'status' => 'done', 'completed_at' => '2026-11-03 12:00:00']);
    ProjectAssignment::create(['task_id' => $done->id, 'member_id' => $this->member->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('answers with the My work payload', function () {
    Sanctum::actingAs($this->user);

    $json = $this->getJson('/api/v1/workspace/my-work')
        ->assertOk()
        ->assertJsonStructure([
            'today' => ['date', 'label', 'bs', 'tomorrow'],
            'groups' => [['key', 'label', 'rows' => [[
                'kind', 'key', 'assignment', 'subtask', 'due', 'days_late', 'people', 'latest_update',
                'task' => ['id', 'slug', 'number', 'code', 'title', 'status', 'priority', 'deadline_at', 'completed_at', 'project' => ['id', 'slug', 'title', 'code', 'label']],
            ]]]],
        ])
        ->json();

    expect($json)->toEqual(app(MyWorkQuery::class)->get($this->user))
        ->and(array_column($json['groups'], 'key'))->toBe(['today', 'tomorrow'])
        ->and($json['groups'][0]['rows'][0]['days_late'])->toBe(2)
        ->and($json['groups'][0]['rows'][0]['latest_update'])->not->toBeNull()
        ->and($json['groups'][1]['rows'][0]['kind'])->toBe('subtask');
});

it('lists the projects the caller was added to lately, as the web does', function () {
    Sanctum::actingAs($this->user);

    $json = $this->getJson('/api/v1/workspace/my-work')
        ->assertOk()
        ->assertJsonStructure(['joined' => [['project' => ['id', 'slug', 'title', 'code', 'label'], 'role', 'joined_at', 'added_by']]])
        ->json();

    expect($json['joined'])->toBe([[
        'project' => ['id' => $this->project->id, 'slug' => $this->project->slug, 'title' => $this->project->title, 'code' => 'MW', 'label' => $this->project->title],
        'role' => 'member',
        'joined_at' => CarbonImmutable::parse('2026-11-04 10:00:00')->toIso8601String(),
        'added_by' => null,
    ]]);
});

it('adds done work when asked', function () {
    Sanctum::actingAs($this->user);

    $json = $this->getJson('/api/v1/workspace/my-work?done=1')->assertOk()->json();

    expect(array_column($json['groups'], 'key'))->toBe(['today', 'tomorrow', 'done'])
        ->and($json)->toEqual(app(MyWorkQuery::class)->get($this->user, null, true));

    $this->getJson('/api/v1/workspace/my-work?done=maybe')->assertUnprocessable()->assertJsonValidationErrors('done');
});

it('turns guests away', function () {
    $this->getJson('/api/v1/workspace/my-work')->assertUnauthorized();
});

it('keeps the old home and my endpoints answering', function () {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/workspace/home')->assertOk()->assertJsonStructure(['scope', 'buckets', 'stats']);
    $this->getJson('/api/v1/workspace/my')->assertOk()->assertJsonStructure(['assignments', 'openTodos', 'projects']);
});
