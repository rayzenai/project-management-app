<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * The app's Home feed (/api/v1/workspace/home). The web's /workspace now
 * opens My work; this endpoint stays for the app.
 */

function homeUser(): User
{
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();

    return $user;
}

/** @return array<string, mixed> the Home feed as the user's app reads it */
function homeFeed(User $user, string $query = ''): array
{
    Sanctum::actingAs($user);

    return test()->getJson('/api/v1/workspace/home'.$query)->assertOk()->json();
}

/**
 * @param  array<string, mixed>  $feed
 * @return array<string, mixed>
 */
function bucketFor(array $feed, string $key): array
{
    return collect($feed['buckets'])->firstWhere('key', $key) ?? [];
}

it('buckets open work by when it is due', function () {
    $user = homeUser();
    $project = Project::factory()->create(['is_public' => true]);

    $overdue = Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()->subDays(3)]);
    $due = Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()]);
    $week = Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()->addDays(4)]);
    $later = Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()->addDays(40)]);
    $none = Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => null, 'deadline_type' => 'rolling']);

    $feed = homeFeed($user);
    $slugs = fn (string $key): array => collect(bucketFor($feed, $key)['tasks'] ?? [])->pluck('slug')->all();

    expect($slugs('overdue'))->toBe([$overdue->slug])
        ->and($slugs('today'))->toBe([$due->slug])
        ->and($slugs('week'))->toBe([$week->slug])
        ->and($slugs('later'))->toBe([$later->slug])
        ->and($slugs('unscheduled'))->toBe([$none->slug]);
});

it('leaves completed work out of the buckets and lists it as recently done', function () {
    $user = homeUser();
    $project = Project::factory()->create(['is_public' => true]);

    Task::factory()->for($project)->create([
        'status' => 'done',
        'deadline_at' => today()->subDays(3),
        'completed_at' => now()->subDay(),
    ]);

    $feed = homeFeed($user);

    expect($feed['stats']['open'])->toBe(0)
        ->and($feed['stats']['overdue'])->toBe(0)
        ->and($feed['recently_done'])->toHaveCount(1);
});

it('defaults to the mine scope when the member has an open assignment', function () {
    $user = homeUser();
    $member = Member::query()->where('user_id', $user->id)->sole();
    $project = Project::factory()->create(['is_public' => true]);

    $mine = Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()]);
    $theirs = Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()]);

    ProjectAssignment::create(['task_id' => $mine->id, 'member_id' => $member->id]);

    $feed = homeFeed($user);
    $slugs = collect(bucketFor($feed, 'today')['tasks'] ?? [])->pluck('slug');

    expect($feed['scope'])->toBe('mine')
        ->and($slugs->all())->toBe([$mine->slug])
        ->and($slugs)->not->toContain($theirs->slug);
});

it('falls back to the all scope when the member is assigned nothing', function () {
    $user = homeUser();
    $project = Project::factory()->create(['is_public' => true]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()]);

    $feed = homeFeed($user);

    expect($feed['scope'])->toBe('all')
        ->and($feed['stats']['today'])->toBe(1);
});

it('honours an explicit scope over the fallback', function () {
    $user = homeUser();
    $project = Project::factory()->create(['is_public' => true]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()]);

    $feed = homeFeed($user, '?scope=mine');

    expect($feed['scope'])->toBe('mine')
        ->and($feed['stats']['open'])->toBe(0);
});

it('never shows work from a project the user cannot see', function () {
    config(['project-management.super_admins' => []]);

    $user = homeUser();
    $foreign = Project::factory()->create(['is_public' => false]);
    $foreign->teams()->attach(Team::factory()->create()->id);
    Task::factory()->for($foreign)->create(['status' => 'in_progress', 'deadline_at' => today()->subDay()]);

    $feed = homeFeed($user);

    expect($feed['stats']['open'])->toBe(0)
        ->and($feed['stats']['overdue'])->toBe(0);
});

it('leaves archived projects out of the home buckets', function () {
    $user = homeUser();
    $project = Project::factory()->create(['is_public' => true, 'archived_at' => now()]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()]);

    expect(homeFeed($user)['stats']['open'])->toBe(0);
});

it('counts work that has not moved in a fortnight as stalled', function () {
    $user = homeUser();
    $project = Project::factory()->create(['is_public' => true]);

    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()->addDay(), 'status_updated_at' => now()->subDays(20)]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()->addDay(), 'status_updated_at' => now()->subDay()]);

    $feed = homeFeed($user);

    expect($feed['stats']['stalled'])->toBe(1)
        ->and($feed['stats']['open'])->toBe(2);
});

it('splits tasks by status for the overview, keeping empty statuses in place', function () {
    $user = homeUser();
    $project = Project::factory()->create(['is_public' => true]);

    Task::factory()->for($project)->create(['status' => 'in_progress']);
    Task::factory()->for($project)->create(['status' => 'in_progress']);
    Task::factory()->for($project)->create(['status' => 'done']);

    $rows = collect(homeFeed($user)['status_breakdown']);

    // Every configured status is present, so the legend never reshuffles.
    expect($rows)->toHaveCount(count(config('project-management.statuses')))
        ->and($rows->firstWhere('value', 'in_progress')['count'])->toBe(2)
        ->and($rows->firstWhere('value', 'done')['count'])->toBe(1)
        ->and($rows->firstWhere('value', 'failed')['count'])->toBe(0);
});

it('rolls each visible project up over the same tasks as the cards', function () {
    $user = homeUser();
    $project = Project::factory()->create(['is_public' => true, 'title' => 'Rollup']);

    Task::factory()->for($project)->create(['status' => 'done', 'completed_at' => now()->subMonth()]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()->subDays(2)]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => today()->addDays(2)]);

    $row = collect(homeFeed($user)['projects'])->firstWhere('title', 'Rollup');

    expect($row['tasks_count'])->toBe(3)
        ->and($row['complete'])->toBe(1)
        ->and($row['percent_complete'])->toBe(33)
        ->and($row['overdue'])->toBe(1)
        ->and($row['due_this_week'])->toBe(1);
});

it('drops projects the caller is not assigned to from the mine rollup', function () {
    $user = homeUser();
    $member = Member::query()->where('user_id', $user->id)->sole();

    $mine = Project::factory()->create(['is_public' => true, 'title' => 'Mine']);
    $other = Project::factory()->create(['is_public' => true, 'title' => 'Not mine']);

    $task = Task::factory()->for($mine)->create(['status' => 'in_progress']);
    Task::factory()->for($other)->create(['status' => 'in_progress']);
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    expect(collect(homeFeed($user, '?scope=mine')['projects'])->pluck('title'))->toContain('Mine')->not->toContain('Not mine')
        // The same projects are all still listed workspace-wide.
        ->and(collect(homeFeed($user, '?scope=all')['projects'])->pluck('title'))->toContain('Mine')->toContain('Not mine');
});
