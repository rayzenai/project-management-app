<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Workspace\QuickAddDispatcher;
use App\Support\QuickAddParser;
use Carbon\Carbon;

it('finds no tokens in a plain title', function () {
    expect(QuickAddParser::parse('Call the ministry about budget'))->toBe([]);
});

it('parses tokens', function (string $input, string $type, string $value) {
    $tokens = QuickAddParser::parse($input);

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]['type'])->toBe($type)
        ->and($tokens[0]['value'])->toBe($value);
})->with([
    'project' => ['Call NEA #100-day', 'project', '100-day'],
    'assignee' => ['Call NEA @sita', 'assignee', 'sita'],
    'priority high' => ['Call NEA !high', 'priority', 'high'],
    'priority urgent shorthand' => ['Call NEA !p1', 'priority', 'urgent'],
    'priority low shorthand' => ['Call NEA !p4', 'priority', 'low'],
    'priority case-insensitive' => ['Call NEA !URGENT', 'priority', 'urgent'],
    'iso date' => ['Call NEA 2026-07-01', 'date', '2026-07-01'],
]);

it('parses relative and month dates against today', function () {
    Carbon::setTestNow('2026-06-12'); // a Friday

    $value = fn (string $input) => QuickAddParser::parse($input)[0]['value'];

    expect($value('x today'))->toBe('2026-06-12')
        ->and($value('x tomorrow'))->toBe('2026-06-13')
        ->and($value('x friday'))->toBe('2026-06-12')
        ->and($value('x mon'))->toBe('2026-06-15')
        ->and($value('x jun 20'))->toBe('2026-06-20')
        ->and($value('x 20 jun'))->toBe('2026-06-20')
        ->and($value('x jan 5'))->toBe('2027-01-05'); // already past → next year

    Carbon::setTestNow();
});

it('reads the Nepali date words as it reads the English ones', function (string $input, string $date) {
    Carbon::setTestNow('2026-11-04'); // a Wednesday

    $tokens = QuickAddParser::parse($input);

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]['type'])->toBe('date')
        ->and($tokens[0]['value'])->toBe($date)
        ->and(QuickAddParser::strip($input, $tokens))->toBe('Report');

    Carbon::setTestNow();
})->with([
    'आज, today' => ['Report आज', '2026-11-04'],
    'भोलि, tomorrow' => ['Report भोलि', '2026-11-05'],
    'पर्सि, the day after tomorrow' => ['Report पर्सि', '2026-11-06'],
    'पर्सी, spelt long' => ['Report पर्सी', '2026-11-06'],
    'आइतबार, Sunday' => ['Report आइतबार', '2026-11-08'],
    'सोमबार, Monday' => ['Report सोमबार', '2026-11-09'],
    'मंगलबार, Tuesday' => ['Report मंगलबार', '2026-11-10'],
    'बुधबार, Wednesday is today' => ['Report बुधबार', '2026-11-04'],
    'बिहिबार, Thursday' => ['Report बिहिबार', '2026-11-05'],
    'बिहीबार, spelt long' => ['Report बिहीबार', '2026-11-05'],
    'शुक्रबार, Friday' => ['Report शुक्रबार', '2026-11-06'],
    'शनिबार, Saturday' => ['Report शनिबार', '2026-11-07'],
    'first word' => ['भोलि Report', '2026-11-05'],
]);

it('never reads a Nepali date word inside another word', function () {
    expect(QuickAddParser::parse('आजको बैठक'))->toBe([])
        ->and(QuickAddParser::parse('भोलिपल्ट Report'))->toBe([])
        ->and(QuickAddParser::parse('Reportआज'))->toBe([]);
});

it('dates a task by a Nepali word through the dispatcher', function () {
    Carbon::setTestNow('2026-11-04');
    $user = User::factory()->withMember()->create();
    $sita = Member::factory()->create(['name' => 'Sita Sharma']);
    $project = Project::factory()->public()->create();
    $this->actingAs($user);

    $result = app(QuickAddDispatcher::class)->dispatch('Call the district भोलि @sita', $project->id, [], null, null, $user);

    $task = Task::query()->where('project_id', $project->id)->sole();

    expect($result->success)->toBeTrue()
        ->and($task->title)->toBe('Call the district')
        ->and($task->deadline_at->toDateString())->toBe('2026-11-05')
        ->and($task->assignments()->pluck('member_id')->all())->toBe([$sita->id]);

    Carbon::setTestNow();
});

it('parses multiple assignees and mixed tokens in any position', function () {
    $tokens = QuickAddParser::parse('#plan Call NEA @sita about dam @ram !high tomorrow');

    $types = array_count_values(array_column($tokens, 'type'));

    expect($types)->toBe(['project' => 1, 'assignee' => 2, 'priority' => 1, 'date' => 1]);
});

it('ignores token-like text mid-word or with unknown values', function () {
    expect(QuickAddParser::parse('learn c#sharp and email a@b.com !mega'))->toBe([]);
});

it('strips consumed tokens and collapses whitespace', function () {
    $title = '#plan Call NEA @sita !high tomorrow';
    $tokens = QuickAddParser::parse($title);

    expect(QuickAddParser::strip($title, $tokens))->toBe('Call NEA');
});

it('keeps unconsumed tokens in the title when stripping only some', function () {
    $title = 'Email @john about contract !high';
    $tokens = QuickAddParser::parse($title);
    $priorityOnly = array_values(array_filter($tokens, fn ($t) => $t['type'] === 'priority'));

    expect(QuickAddParser::strip($title, $priorityOnly))->toBe('Email @john about contract');
});

it('quick-adds with parsed tokens end to end', function () {
    Carbon::setTestNow('2026-06-12');
    $user = User::factory()->withMember()->create(['name' => 'Kiran Timsina']);
    $sita = Member::factory()->create(['name' => 'Sita Sharma']);
    $this->actingAs($user);
    $fallback = Project::factory()->public()->create(['slug' => 'fallback', 'title' => 'Fallback']);
    $plan = Project::factory()->public()->create(['slug' => '100-day-plan', 'title' => 'Government 100-Day Plan']);

    $this->post('/workspace/quick-add', [
        'project_id' => $fallback->id,
        'title' => 'Call NEA chief #100-day tomorrow !urgent @sita',
    ])->assertRedirect();

    $task = Task::query()->where('title', 'Call NEA chief')->first();

    expect($task)->not->toBeNull()
        ->and($task->project_id)->toBe($plan->id)
        ->and($task->priority)->toBe('urgent')
        ->and($task->deadline_at->toDateString())->toBe('2026-06-13')
        ->and($task->assignments()->count())->toBe(1)
        ->and($task->assignments()->first()->member_id)->toBe($sita->id);

    Carbon::setTestNow();
});

it('falls back to the explicit project and self-assignment when tokens do not resolve', function () {
    $user = User::factory()->withMember()->create();
    $this->actingAs($user);
    $project = Project::factory()->public()->create();

    $this->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => 'Email @nonexistent about contract #zzz',
    ])->assertRedirect();

    $task = Task::query()->latest('id')->first();

    // Unresolvable tokens stay in the title; nothing is silently lost.
    expect($task->title)->toBe('Email @nonexistent about contract #zzz')
        ->and($task->project_id)->toBe($project->id)
        ->and($task->assignments()->first()->member_id)->toBe(Member::query()->where('user_id', $user->id)->firstOrFail()->id);
});

it('lets explicit picker values win over parsed tokens', function () {
    $user = User::factory()->withMember()->create();
    $this->actingAs($user);
    $project = Project::factory()->public()->create();

    $this->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => 'Review docs !low',
        'priority' => 'urgent',
        'deadline_at' => '2026-08-01',
    ])->assertRedirect();

    $task = Task::query()->where('title', 'Review docs')->first();

    expect($task->priority)->toBe('urgent')
        ->and($task->deadline_at->toDateString())->toBe('2026-08-01');
});
