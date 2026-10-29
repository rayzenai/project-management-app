<?php

use App\Http\Resources\TaskResource;
use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function numberMigration(): object
{
    return require database_path('migrations/2026_10_30_000002_give_tasks_a_stable_number.php');
}

function insertBareTask(int $projectId, ?int $item, string $slug, bool $trashed = false): int
{
    return DB::table('tasks')->insertGetId([
        'project_id' => $projectId,
        'slug' => $slug,
        'title' => $slug,
        'status' => 'unclear',
        'metadata' => json_encode($item === null ? [] : ['item_number' => $item]),
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => $trashed ? now() : null,
    ]);
}

it('backfills unique item numbers first, then numbers the rest after the highest', function () {
    $project = Project::factory()->create();

    // Put the schema back to before the migration, then seed legacy rows.
    $migration = numberMigration();
    $migration->down();

    $a = insertBareTask($project->id, 3, 'a');
    $b = insertBareTask($project->id, 3, 'b');
    $c = insertBareTask($project->id, null, 'c');
    $d = insertBareTask($project->id, 7, 'd');
    $trashed = insertBareTask($project->id, 5, 'e', trashed: true);

    $migration->up();

    $numbers = DB::table('tasks')->orderBy('id')->pluck('number', 'id')->all();

    expect([$numbers[$a], $numbers[$b], $numbers[$c], $numbers[$d]])->toBe([3, 8, 9, 7])
        ->and($numbers[$trashed])->toBe(5);

    $created = Task::factory()->for($project)->create();
    expect($created->number)->toBe(10);

    $withItem = Task::factory()->for($project)->create(['item_number' => 50]);
    expect($withItem->number)->toBe(11)
        ->and($withItem->item_number)->toBe(50);
});

it('never reuses the number of a deleted task', function () {
    $project = Project::factory()->create();
    $first = Task::factory()->for($project)->create();
    $last = Task::factory()->for($project)->create();

    expect([$first->number, $last->number])->toBe([1, 2]);

    $last->delete();

    expect(Task::factory()->for($project)->create()->number)->toBe(3);
});

it('never reuses the number of a task purged from the trash', function () {
    $project = Project::factory()->create();
    $first = Task::factory()->for($project)->create();
    $last = Task::factory()->for($project)->create();

    expect([$first->number, $last->number])->toBe([1, 2]);

    $last->delete();
    $this->travel(31)->days();
    $this->artisan('workspace:prune-trashed')->assertSuccessful();

    expect(Task::withTrashed()->find($last->id))->toBeNull()
        ->and(Task::factory()->for($project)->create()->number)->toBe(3);
});

it('backfills the project counter to the highest number, trashed tasks included', function () {
    $project = Project::factory()->create();
    $empty = Project::factory()->create();

    $migration = numberMigration();
    $migration->down();

    insertBareTask($project->id, 4, 'a');
    insertBareTask($project->id, 9, 'b', trashed: true);

    $migration->up();

    expect(DB::table('projects')->where('id', $project->id)->value('last_task_number'))->toBe(9)
        ->and(DB::table('projects')->where('id', $empty->id)->value('last_task_number'))->toBe(0);
});

it('backfills only item numbers the column can hold', function () {
    $tooBig = Project::factory()->create();
    $atLimit = Project::factory()->create();

    $migration = numberMigration();
    $migration->down();

    $big = insertBareTask($tooBig->id, 2147483648, 'big');
    $small = insertBareTask($tooBig->id, 5, 'small');
    $limit = insertBareTask($atLimit->id, 2147483647, 'limit');
    $bare = insertBareTask($atLimit->id, null, 'bare');

    $migration->up();

    $numbers = DB::table('tasks')->pluck('number', 'id')->all();

    // Out of range falls through to max + 1, and so does a legacy value at the column's limit.
    expect([$numbers[$big], $numbers[$small]])->toBe([6, 5])
        ->and([$numbers[$limit], $numbers[$bare]])->toBe([1, 2])
        ->and(DB::table('projects')->where('id', $tooBig->id)->value('last_task_number'))->toBe(6)
        ->and(DB::table('projects')->where('id', $atLimit->id)->value('last_task_number'))->toBe(2);
});

it('refuses an item number the database cannot hold', function () {
    Sanctum::actingAs(User::factory()->withMember()->create(), ['*']);
    $project = Project::factory()->public()->create();
    $task = Task::factory()->for($project)->create();

    $this->postJson("/api/v1/workspace/projects/{$project->slug}/tasks", ['title' => 'Big', 'item_number' => 2147483648])
        ->assertUnprocessable()->assertJsonValidationErrors('item_number');
    $this->patchJson("/api/v1/workspace/projects/{$project->slug}/tasks/{$task->slug}", ['item_number' => 2147483648])
        ->assertUnprocessable()->assertJsonValidationErrors('item_number');
    $this->postJson("/api/v1/workspace/projects/{$project->slug}/tasks", ['title' => 'Zero', 'item_number' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors('item_number');
});

it('does not accept a number through mass assignment', function () {
    $task = new Task(['project_id' => 1, 'number' => 99]);

    expect($task->number)->toBeNull();
});

it('refuses a second task with the same number in a project at the database', function () {
    $task = Task::factory()->create();

    DB::table('tasks')->insert([
        'project_id' => $task->project_id,
        'number' => $task->number,
        'slug' => 'dupe',
        'title' => 'dupe',
        'status' => 'unclear',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(UniqueConstraintViolationException::class);

it('ignores a number sent when updating a task over the web', function () {
    $user = User::factory()->withMember()->create();
    $project = Project::factory()->public()->create();
    Task::factory()->for($project)->create();
    $task = Task::factory()->for($project)->create();
    $number = $task->number;
    expect($number)->toBe(2);

    $this->actingAs($user)
        ->patch("/workspace/projects/{$project->slug}/tasks/{$task->slug}", ['title' => 'Renamed', 'number' => 99])
        ->assertRedirect();

    expect($task->fresh()->title)->toBe('Renamed')
        ->and($task->fresh()->number)->toBe($number);
});

it('ignores a number sent when updating a task over the API', function () {
    Sanctum::actingAs(User::factory()->withMember()->create(), ['*']);
    $project = Project::factory()->public()->create();
    Task::factory()->for($project)->create();
    $task = Task::factory()->for($project)->create();
    $number = $task->number;
    expect($number)->toBe(2);

    $this->patchJson("/api/v1/workspace/projects/{$project->slug}/tasks/{$task->slug}", ['title' => 'Renamed', 'number' => 99])
        ->assertOk()
        ->assertJsonPath('data.number', $number);

    expect($task->fresh()->number)->toBe($number);
});

it('numbers tasks created through the API and quick add', function () {
    Sanctum::actingAs(User::factory()->withMember()->create(), ['*']);
    $project = Project::factory()->public()->create();

    $this->postJson("/api/v1/workspace/projects/{$project->slug}/tasks", ['title' => 'One'])
        ->assertCreated()->assertJsonPath('data.number', 1);
    $this->postJson("/api/v1/workspace/projects/{$project->slug}/tasks", ['title' => 'Two', 'item_number' => 50])
        ->assertCreated()->assertJsonPath('data.number', 2);
});

it('exposes the number on the task payload and the short reference', function () {
    $task = Task::factory()->create();

    expect((new TaskResource($task))->resolve()['number'])->toBe($task->number)
        ->and(TaskResource::reference($task)['number'])->toBe($task->number);
});

it('finds a task by its code or its bare number in search', function () {
    $user = User::factory()->withMember()->create();
    $project = Project::factory()->public()->create();
    // Search needs two characters, so the target is number 12.
    Task::factory()->for($project)->count(11)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Quiet title']);

    $this->actingAs($user);

    $byCode = $this->getJson('/workspace/search?q='.$project->code.'-'.$task->number)->assertSuccessful()->json('data.tasks');
    $byNumber = $this->getJson('/workspace/search?q='.$task->number)->assertSuccessful()->json('data.tasks');
    $wrongCode = $this->getJson('/workspace/search?q=ZZZ-'.$task->number)->assertSuccessful()->json('data.tasks');

    expect(collect($byCode)->pluck('id')->all())->toContain($task->id)
        ->and(collect($byNumber)->pluck('id')->all())->toContain($task->id)
        ->and(collect($wrongCode)->pluck('id')->all())->not->toContain($task->id);
});

it('finds a task by a code with the project\'s old key', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['title' => 'Sarkari Gunaso', 'key' => 'SG']);
    Member::factory()->linkedTo($user)->create(['organization_id' => $project->organization_id, 'is_office_admin' => true]);
    Task::factory()->for($project)->count(2)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Quiet title']);

    $this->actingAs($user)->patch("/workspace/projects/{$project->slug}", ['key' => 'SMS'])->assertSessionHasNoErrors();

    expect($task->number)->toBe(3)
        ->and($project->fresh()->key)->toBe('SMS')
        ->and(collect($this->getJson('/workspace/search?q=SG-3')->assertSuccessful()->json('data.tasks'))->pluck('id')->all())->toBe([$task->id])
        ->and(collect($this->getJson('/workspace/search?q=sms-3')->assertSuccessful()->json('data.tasks'))->pluck('id')->all())->toBe([$task->id]);
});

it('falls back to text search for numbers no task can have', function (string $q) {
    $user = User::factory()->withMember()->create();
    $project = Project::factory()->public()->create(['key' => 'SG']);
    $task = Task::factory()->for($project)->create(['title' => "Budget line {$q}"]);

    $this->actingAs($user);

    expect(collect($this->getJson('/workspace/search?q='.$q)->assertSuccessful()->json('data.tasks'))->pluck('id')->all())
        ->toBe([$task->id]);
})->with(['bare' => '99999999999', 'code' => 'SG-99999999999', 'one past the limit' => '2147483648']);

it('numbers tasks created through quick add, web and API', function () {
    $user = User::factory()->withMember()->create();
    $project = Project::factory()->public()->create();
    Task::factory()->for($project)->create();
    Task::factory()->for($project)->create();

    $this->actingAs($user)
        ->post('/workspace/quick-add', ['project_id' => $project->id, 'title' => 'Web quick'])
        ->assertRedirect();

    expect(Task::query()->where('title', 'Web quick')->sole()->number)->toBe(3);

    Sanctum::actingAs($user, ['*']);
    $this->postJson('/api/v1/workspace/quick-add', ['project_id' => $project->id, 'title' => 'Api quick'])
        ->assertSuccessful();

    expect(Task::query()->where('title', 'Api quick')->sole()->number)->toBe(4);
});

it('claims only plan-sized item numbers as task numbers in the backfill', function () {
    $project = Project::factory()->create();

    $migration = numberMigration();
    $migration->down();

    $plan = insertBareTask($project->id, 100000, 'plan');
    $noise = insertBareTask($project->id, 2147483647, 'noise');
    $over = insertBareTask($project->id, 100001, 'over');

    $migration->up();

    $numbers = DB::table('tasks')->pluck('number', 'id')->all();

    expect([$numbers[$plan], $numbers[$noise], $numbers[$over]])->toBe([100000, 100001, 100002])
        ->and(Task::factory()->for($project)->create()->number)->toBe(100003);
});

it('leaves the item number empty when the next one would not fit', function () {
    $user = User::factory()->withMember()->create();
    $project = Project::factory()->public()->create();
    Task::factory()->for($project)->create(['item_number' => 2147483647]);

    expect(Task::nextItemNumber($project->id))->toBeNull();

    $this->actingAs($user);
    $this->post("/workspace/projects/{$project->slug}/tasks", ['title' => 'Full create'])->assertRedirect();
    $this->post('/workspace/quick-add', ['project_id' => $project->id, 'title' => 'Full quick'])->assertRedirect();

    expect(Task::query()->where('title', 'Full create')->sole()->item_number)->toBeNull()
        ->and(Task::query()->where('title', 'Full quick')->sole()->item_number)->toBeNull();
});

it('counts trashed tasks when numbering the next item', function () {
    $project = Project::factory()->create();
    Task::factory()->for($project)->create(['item_number' => 4])->delete();
    Task::factory()->for($project)->create(['item_number' => 2]);

    expect(Task::nextItemNumber($project->id))->toBe(5)
        ->and(Task::nextItemNumber(Project::factory()->create()->id))->toBe(1);
});

it('orders by a legacy item number larger than an integer', function () {
    $project = Project::factory()->create();
    $big = Task::factory()->for($project)->create(['item_number' => 3000000000]);
    $small = Task::factory()->for($project)->create(['item_number' => 5]);

    expect(Task::query()->where('project_id', $project->id)->orderByItemNumber()->pluck('id')->all())->toBe([$small->id, $big->id])
        ->and($project->tasks()->pluck('id')->all())->toBe([$small->id, $big->id])
        ->and(Task::query()->where('project_id', $project->id)->orderByItemNumber('desc')->pluck('id')->all())->toBe([$big->id, $small->id]);
});
