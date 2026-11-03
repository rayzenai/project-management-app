<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use App\Queries\MyWorkQuery;
use App\Support\WorkspaceAccess;
use App\Support\WorkspaceOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

/*
 * My work: one list of a person's own work, grouped by when it is due.
 * Today is Wednesday 2026-11-04; without attendance the week runs Sunday
 * to Friday, so Tomorrow is Thursday and Coming up ends Saturday 14th.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-11-04 10:00:00');

    $this->project = Project::factory()->create(['key' => 'MW', 'title' => 'Roads']);
    $this->user = User::factory()->create();
    $this->member = Member::factory()->linkedTo($this->user)->create(['organization_id' => $this->project->organization_id]);
    $this->project->ensureTeam()->members()->attach($this->member->id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * An open task in the project, on the member unless `$assignment` is null.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>|null  $assignment
 */
function myTask(array $attributes = [], ?array $assignment = []): Task
{
    $task = Task::factory()->create([
        'project_id' => test()->project->id,
        'status' => 'in_progress',
        'deadline_type' => 'rolling',
        'deadline_at' => null,
        ...$attributes,
    ]);

    if ($assignment !== null) {
        ProjectAssignment::create(['task_id' => $task->id, 'member_id' => test()->member->id, ...$assignment]);
    }

    return $task;
}

/** @return array<string, mixed> the My work page's props */
function myWorkPage(?User $user = null, string $uri = '/workspace/my'): array
{
    $props = null;

    test()->actingAs($user ?? test()->user)->get($uri)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('MyWork');
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, list<array<string, mixed>>> each group's rows by its key
 */
function myGroups(array $payload): array
{
    return collect($payload['groups'])->mapWithKeys(fn (array $group) => [$group['key'] => $group['rows']])->all();
}

/**
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function myGroupKeys(array $payload, string $group): array
{
    return array_column(myGroups($payload)[$group] ?? [], 'key');
}

it('places tasks by their date', function () {
    $late = myTask(['deadline_at' => '2026-11-02']);
    $today = myTask(['deadline_at' => '2026-11-04']);
    $tomorrow = myTask(['deadline_at' => '2026-11-05']);
    $comingUp = myTask(['deadline_at' => '2026-11-12']);
    $weekEnd = myTask(['deadline_at' => '2026-11-14']);
    $later = myTask(['deadline_at' => '2026-11-15']);
    $farLater = myTask(['deadline_at' => '2026-11-20']);
    $none = myTask();

    $payload = myWorkPage();
    $groups = myGroups($payload);

    expect($payload['today'])->toBe(['date' => '2026-11-04', 'label' => 'Wednesday, 4 November', 'bs' => 'कार्तिक १८', 'tomorrow' => '2026-11-05'])
        ->and(array_column($payload['groups'], 'key'))->toBe(['today', 'tomorrow', 'coming_up', 'later', 'no_date'])
        ->and(array_column($payload['groups'], 'label'))->toBe(['Today', 'Tomorrow', 'Coming up', 'Later', 'No date'])
        ->and(myGroupKeys($payload, 'today'))->toBe(["task-{$late->id}", "task-{$today->id}"])
        ->and(array_column($groups['today'], 'days_late'))->toBe([2, 0])
        ->and(myGroupKeys($payload, 'tomorrow'))->toBe(["task-{$tomorrow->id}"])
        ->and(myGroupKeys($payload, 'coming_up'))->toBe(["task-{$comingUp->id}", "task-{$weekEnd->id}"])
        ->and(myGroupKeys($payload, 'later'))->toBe(["task-{$later->id}", "task-{$farLater->id}"])
        ->and(myGroupKeys($payload, 'no_date'))->toBe(["task-{$none->id}"]);
});

it('gives each row the task, its project, its date and its people', function () {
    $program = Program::factory()->create(['organization_id' => $this->project->organization_id, 'name' => 'Transport']);
    $this->project->forceFill(['program_id' => $program->id])->save();
    $task = myTask(['title' => 'Fix the bridge', 'deadline_at' => '2026-11-05', 'priority' => 'high'], ['is_focused' => true, 'personal_due_at' => '2026-11-05']);
    $colleague = Member::factory()->create(['organization_id' => $this->project->organization_id, 'name' => 'Asha']);
    $retired = Member::factory()->inactive()->create(['organization_id' => $this->project->organization_id]);
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $colleague->id]);
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $retired->id]);
    $assignment = ProjectAssignment::query()->where('member_id', $this->member->id)->sole();

    $row = myGroups(myWorkPage())['tomorrow'][0];

    expect($row)->toBe([
        'kind' => 'task',
        'key' => "task-{$task->id}",
        'assignment' => ['id' => $assignment->id, 'is_focused' => true, 'snoozed_until' => null, 'personal_due_at' => '2026-11-05'],
        'task' => [
            'id' => $task->id,
            'slug' => $task->slug,
            'number' => $task->number,
            'code' => "MW-{$task->number}",
            'title' => 'Fix the bridge',
            'status' => 'in_progress',
            'priority' => 'high',
            'deadline_at' => '2026-11-05',
            'completed_at' => null,
            'project' => ['id' => $this->project->id, 'slug' => $this->project->slug, 'title' => 'Roads', 'code' => 'MW', 'label' => 'Roads (Transport)'],
        ],
        'subtask' => null,
        'todo' => null,
        'due' => '2026-11-05',
        'days_late' => 0,
        'people' => collect([['id' => $colleague->id, 'name' => 'Asha'], ['id' => $this->member->id, 'name' => $this->member->name]])->sortBy('name')->values()->all(),
        'latest_update' => null,
    ]);
});

it('uses the personal date to place a row but the task date to call it late', function () {
    $early = myTask(['deadline_at' => '2026-11-20'], ['personal_due_at' => '2026-11-03']);
    $late = myTask(['deadline_at' => '2026-11-02'], ['personal_due_at' => '2026-11-10']);
    $later = myTask(['deadline_at' => '2026-11-05'], ['personal_due_at' => '2026-11-25']);

    $groups = myGroups(myWorkPage());
    $today = collect($groups['today'])->keyBy('key');

    expect($today->keys()->all())->toBe(["task-{$late->id}", "task-{$early->id}"])
        ->and($today["task-{$early->id}"]['days_late'])->toBe(0)
        ->and($today["task-{$early->id}"]['due'])->toBe('2026-11-03')
        // Late by the task's own deadline, though the person's own date is next week.
        ->and($today["task-{$late->id}"]['days_late'])->toBe(2)
        ->and($today["task-{$late->id}"]['due'])->toBe('2026-11-10')
        ->and(array_column($groups['later'], 'key'))->toBe(["task-{$later->id}"]);
});

it('counts lateness against the day it is asked for', function () {
    $task = myTask(['deadline_at' => '2026-11-05']);

    $payload = app(MyWorkQuery::class)->get($this->user, today: CarbonImmutable::parse('2026-11-06'));
    $row = myGroups($payload)['today'][0];

    expect($payload['today']['date'])->toBe('2026-11-06')
        ->and($row['key'])->toBe("task-{$task->id}")
        ->and($row['days_late'])->toBe(1);
});

it('keeps snoozed tasks only in Snoozed', function () {
    $snoozedLate = myTask(['deadline_at' => '2026-11-01'], ['snoozed_until' => '2026-11-06']);
    $snoozedUndated = myTask([], ['snoozed_until' => '2026-12-01']);
    $wokeUp = myTask(['deadline_at' => '2026-11-05'], ['snoozed_until' => '2026-11-04']);

    $payload = myWorkPage();
    $everyKey = collect($payload['groups'])->flatMap(fn (array $group) => array_column($group['rows'], 'key'));

    expect(array_column($payload['groups'], 'key'))->toBe(['tomorrow', 'snoozed'])
        ->and(myGroupKeys($payload, 'snoozed'))->toBe(["task-{$snoozedLate->id}", "task-{$snoozedUndated->id}"])
        ->and(myGroups($payload)['snoozed'][0]['assignment']['snoozed_until'])->toBe('2026-11-06')
        ->and(myGroupKeys($payload, 'tomorrow'))->toBe(["task-{$wokeUp->id}"])
        ->and($everyKey->duplicates()->all())->toBe([]);
});

it('puts starred rows first in their group', function () {
    $first = myTask(['deadline_at' => '2026-11-09']);
    $starred = myTask(['deadline_at' => '2026-11-12'], ['is_focused' => true]);
    $second = myTask(['deadline_at' => '2026-11-10']);
    $lateByOne = myTask(['deadline_at' => '2026-11-03']);
    $lateByThree = myTask(['deadline_at' => '2026-11-01']);
    $starredToday = myTask(['deadline_at' => '2026-11-04'], ['is_focused' => true]);
    $dueToday = myTask(['deadline_at' => '2026-11-04']);

    $payload = myWorkPage();

    expect(myGroupKeys($payload, 'coming_up'))->toBe(["task-{$starred->id}", "task-{$first->id}", "task-{$second->id}"])
        // Today: the late ones first, most late first, then starred.
        ->and(myGroupKeys($payload, 'today'))->toBe(["task-{$lateByThree->id}", "task-{$lateByOne->id}", "task-{$starredToday->id}", "task-{$dueToday->id}"]);
});

it('lists tasks the user created for others under You asked others', function () {
    $colleague = Member::factory()->create(['organization_id' => $this->project->organization_id, 'name' => 'Bibek']);
    $asked = myTask(['created_by' => $this->user->id, 'deadline_at' => '2026-11-02'], null);
    ProjectAssignment::create(['task_id' => $asked->id, 'member_id' => $colleague->id]);
    $sharedWithMe = myTask(['created_by' => $this->user->id, 'deadline_at' => '2026-11-05']);
    ProjectAssignment::create(['task_id' => $sharedWithMe->id, 'member_id' => $colleague->id]);
    myTask(['created_by' => $this->user->id], null); // on nobody
    $closed = myTask(['created_by' => $this->user->id, 'status' => 'done'], null);
    ProjectAssignment::create(['task_id' => $closed->id, 'member_id' => $colleague->id]);
    $someoneElses = myTask([], null);
    ProjectAssignment::create(['task_id' => $someoneElses->id, 'member_id' => $colleague->id]);

    $payload = myWorkPage();
    $row = myGroups($payload)['asked_others'][0];

    expect(array_column($payload['groups'], 'key'))->toBe(['tomorrow', 'asked_others'])
        ->and(myGroupKeys($payload, 'asked_others'))->toBe(["task-{$asked->id}"])
        ->and(myGroupKeys($payload, 'tomorrow'))->toBe(["task-{$sharedWithMe->id}"])
        ->and($row['assignment'])->toBeNull()
        ->and($row['people'])->toBe([['id' => $colleague->id, 'name' => 'Bibek']])
        ->and($row['due'])->toBe('2026-11-02')
        ->and($row['days_late'])->toBe(2);
});

it('shows the newest update of the last 7 days, one line', function () {
    $author = User::factory()->create(['name' => 'Bibek']);
    $task = myTask(['deadline_at' => '2026-11-04']);
    $stale = myTask(['deadline_at' => '2026-11-04']);
    TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $author->id, 'body' => 'Older news', 'created_at' => '2026-11-02 09:00:00']);
    $long = "Three districts\n\n have   not sent their data yet. ".str_repeat('More words here. ', 12);
    TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $author->id, 'body' => $long, 'created_at' => '2026-11-03 09:00:00']);
    TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $author->id, 'body' => 'Withdrawn', 'created_at' => '2026-11-03 10:00:00'])->delete();
    TaskComment::factory()->create(['task_id' => $stale->id, 'user_id' => $author->id, 'body' => 'Last week', 'created_at' => '2026-10-27 09:00:00']);

    $rows = collect(myGroups(myWorkPage())['today'])->keyBy('key');
    $update = $rows["task-{$task->id}"]['latest_update'];

    expect($update['author'])->toBe('Bibek')
        ->and($update['at'])->toBe(CarbonImmutable::parse('2026-11-03 09:00:00')->toIso8601String())
        ->and($update['body'])->toStartWith('Three districts have not sent their data yet. More words')
        ->and($update['body'])->not->toContain("\n")
        ->and(mb_strlen($update['body']))->toBe(141)
        ->and($update['body'])->toEndWith('…')
        ->and($rows["task-{$stale->id}"]['latest_update'])->toBeNull();
});

it('shows mentions in an update as names, never cut inside one', function () {
    $short = myTask(['deadline_at' => '2026-11-04']);
    $long = myTask(['deadline_at' => '2026-11-04']);
    TaskComment::factory()->create(['task_id' => $short->id, 'body' => '@[Asha Rai](member:12) please call back.', 'created_at' => '2026-11-03 09:00:00']);
    TaskComment::factory()->create(['task_id' => $long->id, 'body' => str_repeat('word ', 26).'@[Asha Rai](member:12) needs to send the district data today.', 'created_at' => '2026-11-03 09:00:00']);

    $rows = collect(myGroups(myWorkPage())['today'])->keyBy('key');
    $longBody = $rows["task-{$long->id}"]['latest_update']['body'];

    expect($rows["task-{$short->id}"]['latest_update']['body'])->toBe('@Asha Rai please call back.')
        // The raw token would have been cut at "@[Asha Ra"; the name stays whole.
        ->and($longBody)->toEndWith('@Asha Rai…')
        ->and($longBody)->not->toContain('member:')
        ->and($longBody)->not->toContain('@[');
});

it('reads the list in the same number of queries however many tasks there are', function () {
    $author = User::factory()->create();
    $colleague = Member::factory()->create(['organization_id' => $this->project->organization_id]);
    $program = Program::factory()->create(['organization_id' => $this->project->organization_id]);
    $count = function (int $tasks) use ($author, $colleague, $program): int {
        $project = Project::factory()->create(['organization_id' => $this->project->organization_id, 'program_id' => $program->id]);
        $project->ensureTeam()->members()->attach($this->member->id);
        foreach (range(1, $tasks) as $i) {
            $task = myTask(['project_id' => $project->id, 'deadline_at' => '2026-11-0'.(2 + $i % 6)]);
            ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $colleague->id]);
            TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $author->id, 'created_at' => '2026-11-03 09:00:00']);
            Subtask::create(['task_id' => $task->id, 'user_id' => $this->user->id, 'body' => "Step {$i}", 'due_at' => '2026-11-05']);
            $asked = myTask(['project_id' => $project->id, 'created_by' => $this->user->id], null);
            ProjectAssignment::create(['task_id' => $asked->id, 'member_id' => $colleague->id]);
        }

        WorkspaceAccess::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $payload = app(MyWorkQuery::class)->get($this->user->fresh(), null, true);
        DB::disableQueryLog();

        expect(collect($payload['groups'])->sum(fn (array $group) => count($group['rows'])))->toBeGreaterThanOrEqual($tasks * 3);

        return count(DB::getQueryLog());
    };

    expect($count(2))->toBe($count(6));
});

it('leaves out closed tasks, archived projects and projects the user cannot see', function () {
    $open = myTask(['deadline_at' => '2026-11-05']);
    myTask(['status' => 'done', 'deadline_at' => '2026-11-05']);
    myTask(['status' => 'failed', 'deadline_at' => '2026-11-01']);
    $archived = Project::factory()->create(['organization_id' => $this->project->organization_id, 'archived_at' => now()]);
    $archived->ensureTeam()->members()->attach($this->member->id);
    myTask(['project_id' => $archived->id, 'deadline_at' => '2026-11-05']);
    // Still assigned on a project of another organization they cannot see.
    $foreign = Project::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $foreign->teams()->attach(Team::factory()->create()->id);
    myTask(['project_id' => $foreign->id, 'deadline_at' => '2026-11-05']);
    myTask(['deadline_at' => '2026-11-05'])->delete();

    $payload = myWorkPage();

    expect(array_column($payload['groups'], 'key'))->toBe(['tomorrow'])
        ->and(myGroupKeys($payload, 'tomorrow'))->toBe(["task-{$open->id}"]);
});

it('places dated subtasks by their date', function () {
    $task = myTask(['deadline_at' => '2026-11-20']);
    $notMine = myTask(['deadline_at' => '2026-11-20'], null);
    $tomorrowStep = Subtask::create(['task_id' => $task->id, 'user_id' => $this->user->id, 'body' => 'Call the engineer', 'due_at' => '2026-11-05']);
    $lateStep = Subtask::create(['task_id' => $notMine->id, 'user_id' => $this->user->id, 'body' => 'Send the letter', 'due_at' => '2026-11-01']);
    Subtask::create(['task_id' => $task->id, 'user_id' => $this->user->id, 'body' => 'Someday', 'due_at' => null]);
    Subtask::create(['task_id' => $task->id, 'user_id' => $this->user->id, 'body' => 'Done already', 'due_at' => '2026-11-05', 'is_done' => true]);
    Subtask::create(['task_id' => $task->id, 'user_id' => User::factory()->create()->id, 'body' => 'Not mine', 'due_at' => '2026-11-05']);
    $archived = Project::factory()->create(['organization_id' => $this->project->organization_id, 'archived_at' => now()]);
    Subtask::create(['task_id' => myTask(['project_id' => $archived->id], null)->id, 'user_id' => $this->user->id, 'body' => 'Archived', 'due_at' => '2026-11-05']);
    // Work on a closed task has stopped: its to-dos leave the list too.
    Subtask::create(['task_id' => myTask(['status' => 'done'])->id, 'user_id' => $this->user->id, 'body' => 'On a done task', 'due_at' => '2026-11-05']);
    Subtask::create(['task_id' => myTask(['status' => 'failed'], null)->id, 'user_id' => $this->user->id, 'body' => 'On a failed task', 'due_at' => '2026-11-05']);

    $payload = myWorkPage();
    $row = myGroups($payload)['tomorrow'][0];

    expect(myGroupKeys($payload, 'tomorrow'))->toBe(["subtask-{$tomorrowStep->id}"])
        ->and(myGroupKeys($payload, 'today'))->toBe(["subtask-{$lateStep->id}"])
        ->and(myGroupKeys($payload, 'later'))->toBe(["task-{$task->id}"])
        ->and($row['kind'])->toBe('subtask')
        ->and($row['assignment'])->toBeNull()
        ->and($row['subtask'])->toBe(['id' => $tomorrowStep->id, 'body' => 'Call the engineer', 'due_at' => '2026-11-05', 'is_done' => false])
        ->and($row['task']['id'])->toBe($task->id)
        ->and($row['due'])->toBe('2026-11-05')
        ->and($row['days_late'])->toBe(0);
});

it('sorts subtasks by their date among the tasks', function () {
    $later = myTask(['deadline_at' => '2026-11-12']);
    $sooner = myTask(['deadline_at' => '2026-11-09']);
    $step = Subtask::create(['task_id' => $later->id, 'user_id' => $this->user->id, 'body' => 'Measure', 'due_at' => '2026-11-10']);

    expect(myGroupKeys(myWorkPage(), 'coming_up'))->toBe(["task-{$sooner->id}", "subtask-{$step->id}", "task-{$later->id}"]);
});

it('shows done tasks of the last 7 days only when asked', function () {
    myTask(['deadline_at' => '2026-11-05']);
    $yesterday = myTask(['status' => 'done', 'completed_at' => '2026-11-03 15:00:00']);
    $lastWeek = myTask(['status' => 'done', 'completed_at' => '2026-10-29 15:00:00']);
    myTask(['status' => 'done', 'completed_at' => '2026-10-26 15:00:00']);
    myTask(['status' => 'failed']);

    $without = myWorkPage();
    $with = myWorkPage(uri: '/workspace/my?done=1');
    $done = myGroups($with)['done'];

    expect(array_column($without['groups'], 'key'))->toBe(['tomorrow'])
        ->and(array_column($with['groups'], 'key'))->toBe(['tomorrow', 'done'])
        ->and(array_column($with['groups'], 'label'))->toBe(['Tomorrow', 'Done'])
        ->and(array_column($done, 'key'))->toBe(["task-{$yesterday->id}", "task-{$lastWeek->id}"])
        ->and($done[0]['task']['completed_at'])->toBe(CarbonImmutable::parse('2026-11-03 15:00:00')->toIso8601String())
        ->and($done[0]['assignment'])->not->toBeNull()
        ->and($done[0]['days_late'])->toBe(0);
});

it("treats Friday's tomorrow as Sunday", function () {
    CarbonImmutable::setTestNow('2026-11-06 10:00:00');
    $saturday = myTask(['deadline_at' => '2026-11-07']);
    $sunday = myTask(['deadline_at' => '2026-11-08']);
    $monday = myTask(['deadline_at' => '2026-11-09']);

    $payload = myWorkPage();

    // "Snooze until tomorrow" on a Friday wakes it on Sunday, too.
    expect($payload['today']['tomorrow'])->toBe('2026-11-08')
        ->and(myGroupKeys($payload, 'tomorrow'))->toBe(["task-{$saturday->id}", "task-{$sunday->id}"])
        ->and(myGroupKeys($payload, 'coming_up'))->toBe(["task-{$monday->id}"]);
});

it('works for a super-admin with no member', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    myTask(['deadline_at' => '2026-11-04']);

    $payload = app(MyWorkQuery::class)->get($boss);

    expect($payload['groups'])->toBe([])
        ->and($payload['today']['date'])->toBe('2026-11-04')
        ->and(Member::query()->where('user_id', $boss->id)->exists())->toBeFalse()
        ->and(myWorkPage($boss)['groups'])->toBe([]);
});

it('follows the organization switch on the web only', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $bossMember = Member::factory()->linkedTo($boss)->create(['organization_id' => null]);
    $other = Organization::factory()->create();
    $tasks = [];
    foreach ([$this->project->organization_id, $other->id] as $organizationId) {
        $project = Project::factory()->create(['organization_id' => $organizationId]);
        $tasks[$organizationId] = Task::factory()->create(['project_id' => $project->id, 'status' => 'in_progress', 'deadline_at' => '2026-11-05']);
        ProjectAssignment::create(['task_id' => $tasks[$organizationId]->id, 'member_id' => $bossMember->id]);
    }
    WorkspaceOrganization::choose($boss, $other->id);

    $web = myWorkPage($boss);
    $api = $this->actingAs($boss, 'sanctum')->getJson('/api/v1/workspace/my-work')->assertOk()->json();

    expect(myGroupKeys($web, 'tomorrow'))->toBe(["task-{$tasks[$other->id]->id}"])
        ->and(collect(myGroupKeys($api, 'tomorrow'))->sort()->values()->all())->toBe(collect($tasks)->map(fn (Task $task) => "task-{$task->id}")->sort()->values()->all());
});

/**
 * Puts the member on the project's team at `$at`, added by `$by` (nobody
 * signed in when null), as the observer records it.
 */
function joinTeam(Project $project, string $at, ?User $by = null, string $role = 'member'): void
{
    $now = CarbonImmutable::now();
    CarbonImmutable::setTestNow($at);
    $by === null ? auth()->forgetUser() : test()->actingAs($by);
    $project->ensureTeam()->members()->attach(test()->member->id, ['role' => $role]);
    auth()->forgetUser();
    CarbonImmutable::setTestNow($now);
}

it('lists projects the user was added to in the last 14 days', function () {
    $kiran = User::factory()->create(['name' => 'Kiran Timsina']);
    $program = Program::factory()->create(['organization_id' => $this->project->organization_id, 'name' => 'Digital']);
    $added = Project::factory()->create(['organization_id' => $this->project->organization_id, 'program_id' => $program->id, 'key' => 'OAS', 'title' => 'Office Automation System']);
    $managed = Project::factory()->create(['organization_id' => $this->project->organization_id, 'title' => 'Archive']);
    $old = Project::factory()->create(['organization_id' => $this->project->organization_id]);
    $archived = Project::factory()->create(['organization_id' => $this->project->organization_id, 'archived_at' => '2026-11-03 12:00:00']);
    $ownProject = Project::factory()->create(['organization_id' => $this->project->organization_id]);
    $foreign = Project::factory()->create(['organization_id' => Organization::factory()->create()->id, 'title' => 'Shared']);
    $foreign->ensureTeam()->forceFill(['is_cross_organization' => true])->save();

    joinTeam($added, '2026-11-02 09:00:00', $kiran);
    joinTeam($managed, '2026-11-03 12:00:00', $kiran, 'leader');
    joinTeam($old, '2026-10-15 09:00:00', $kiran);
    joinTeam($archived, '2026-11-02 09:00:00', $kiran);
    // They made it and manage it themselves: not news to them.
    joinTeam($ownProject, '2026-11-03 15:00:00', $this->user, 'leader');
    joinTeam($foreign, '2026-11-01 09:00:00', $kiran);

    $joined = myWorkPage()['joined'];

    expect(array_column(array_column($joined, 'project'), 'id'))->toBe([$this->project->id, $managed->id, $added->id, $foreign->id])
        // Roads: put on its team with nobody signed in, so nobody to name.
        ->and($joined[0]['added_by'])->toBeNull()
        ->and($joined[1])->toBe([
            'project' => ['id' => $managed->id, 'slug' => $managed->slug, 'title' => 'Archive', 'code' => $managed->code, 'label' => 'Archive'],
            'role' => 'leader',
            'joined_at' => CarbonImmutable::parse('2026-11-03 12:00:00')->toIso8601String(),
            'added_by' => 'Kiran Timsina',
        ])
        ->and($joined[2])->toBe([
            'project' => ['id' => $added->id, 'slug' => $added->slug, 'title' => 'Office Automation System', 'code' => 'OAS', 'label' => 'Office Automation System (Digital)'],
            'role' => 'member',
            'joined_at' => CarbonImmutable::parse('2026-11-02 09:00:00')->toIso8601String(),
            'added_by' => 'Kiran Timsina',
        ])
        ->and($joined[3]['project']['title'])->toBe('Shared');
});

it('names who added them only from this membership, keeps the five newest and follows the organization switch', function () {
    $kiran = User::factory()->create(['name' => 'Kiran Timsina']);
    $asha = User::factory()->create(['name' => 'Asha']);
    $again = Project::factory()->create(['organization_id' => $this->project->organization_id]);
    joinTeam($again, '2026-11-01 09:00:00', $kiran);
    $this->actingAs($kiran);
    $again->ensureTeam()->members()->detach($this->member->id);
    auth()->forgetUser();
    // Back on it, put there by nobody signed in: Kiran's old add is not this one.
    joinTeam($again, '2026-11-03 09:00:00');

    $byAsha = collect(range(1, 5))->map(function (int $i) use ($asha): Project {
        $project = Project::factory()->create(['organization_id' => $this->project->organization_id]);
        joinTeam($project, "2026-11-02 0{$i}:00:00", $asha);

        return $project;
    });

    $joined = app(MyWorkQuery::class)->get($this->user);

    expect(array_column(array_column($joined['joined'], 'project'), 'id'))->toBe([$this->project->id, $again->id, ...$byAsha->reverse()->take(3)->pluck('id')->all()])
        ->and($joined['joined'][1]['added_by'])->toBeNull()
        ->and($joined['joined'][2]['added_by'])->toBe('Asha');

    // A super-admin switched to another organization sees none of these.
    config(['project-management.super_admins' => [$this->user->email]]);
    WorkspaceOrganization::choose($this->user, Organization::factory()->create()->id);
    WorkspaceAccess::flush();

    expect(myWorkPage()['joined'])->toBe([]);
});

it('keeps the joined list to one query whatever the count', function () {
    $kiran = User::factory()->create(['name' => 'Kiran Timsina']);
    $program = Program::factory()->create(['organization_id' => $this->project->organization_id]);
    $count = function (int $memberships) use ($kiran, $program): int {
        foreach (range(1, $memberships) as $i) {
            $project = Project::factory()->create(['organization_id' => $this->project->organization_id, 'program_id' => $program->id]);
            joinTeam($project, "2026-11-03 0{$i}:00:00", $kiran);
        }

        WorkspaceAccess::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $payload = app(MyWorkQuery::class)->get($this->user->fresh());
        DB::disableQueryLog();

        expect(collect($payload['joined'])->where('added_by', 'Kiran Timsina'))->not->toBeEmpty();

        return count(DB::getQueryLog());
    };

    expect($count(1))->toBe($count(3));
});

it('has no joined list without a member', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    expect(app(MyWorkQuery::class)->get($boss)['joined'])->toBe([]);
});

it('sends /workspace to My work, keeping the query string', function () {
    $this->actingAs($this->user)->get('/workspace')->assertRedirect('/workspace/my');
    $this->actingAs($this->user)->get('/workspace?done=1')->assertRedirect('/workspace/my?done=1');
});

it('lands on My work after signing in', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);

    $this->post('/workspace/login', ['email' => $user->email, 'password' => 'secret-pass'])
        ->assertRedirect('/workspace/my');
});

it("sends the organization switch to My work when it wasn't used on a workspace page", function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    $this->actingAs($boss)->from('https://elsewhere.example/steal')
        ->put('/workspace/organization', ['organization' => $this->project->organization_id])
        ->assertRedirect('/workspace/my');
});

it('links a notification without a task to My work', function () {
    $notification = new class
    {
        use BuildsWorkspaceNotification;

        public function url(): string
        {
            return $this->taskUrl(null);
        }
    };

    expect($notification->url())->toBe('/workspace/my');
});
