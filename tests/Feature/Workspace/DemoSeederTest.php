<?php

use App\Models\Member;
use App\Models\ProjectAssignment;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\WorkspaceDemoSeeder;

/**
 * The demo workspace exists to make every screen show something. A login with
 * no assignments lands on an empty My Workspace and a 0% Home, which is the
 * failure this guards.
 */
it('leaves no seeded login with an empty personal workspace', function () {
    config(['project-management.super_admins' => ['pmopm@example.com', 'namru.mail@gmail.com']]);

    $this->seed(WorkspaceDemoSeeder::class);

    expect(User::query()->count())->toBe(7);

    User::query()->get()->each(function (User $user): void {
        $member = Member::query()->where('user_id', $user->id)->sole();

        $open = ProjectAssignment::query()
            ->where('member_id', $member->id)
            ->whereHas('task', fn ($q) => $q->incomplete()->forActiveProjects())
            ->count();

        $todos = Subtask::query()->where('user_id', $user->id)->where('is_done', false)->count();

        expect($open)->toBeGreaterThan(0, "{$user->email} has no open assignment")
            ->and($todos)->toBeGreaterThan(0, "{$user->email} has no open todo");
    });
});

it('covers every status and the derived states each screen renders differently', function () {
    $this->seed(WorkspaceDemoSeeder::class);

    $tasks = Task::query()->get();

    foreach (array_keys((array) config('project-management.statuses')) as $status) {
        expect($tasks->where('status', $status))->not->toBeEmpty("no task in status {$status}");
    }

    expect($tasks->filter->is_late)->not->toBeEmpty()
        ->and($tasks->whereNull('deadline_at'))->not->toBeEmpty()
        // A finished task that landed after its deadline — is_late without being incomplete.
        ->and($tasks->filter(fn (Task $t): bool => $t->isComplete() && $t->is_late))->not->toBeEmpty()
        ->and($tasks->pluck('freshness.bucket')->unique())->toContain('cold', 'stalled', 'moved');
});

it('is idempotent', function () {
    $this->seed(WorkspaceDemoSeeder::class);

    $before = [
        'tasks' => Task::query()->count(),
        'assignments' => ProjectAssignment::query()->count(),
        'subtasks' => Subtask::query()->count(),
        'members' => Member::query()->count(),
    ];

    $this->seed(WorkspaceDemoSeeder::class);

    expect([
        'tasks' => Task::query()->count(),
        'assignments' => ProjectAssignment::query()->count(),
        'subtasks' => Subtask::query()->count(),
        'members' => Member::query()->count(),
    ])->toBe($before);
});
