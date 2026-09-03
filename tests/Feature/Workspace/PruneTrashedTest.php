<?php

use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Config;

it('force-deletes trashed rows older than the TTL but keeps newer ones', function () {
    Config::set('project-management.trash_ttl_days', 30);

    $old = Task::factory()->create();
    $old->delete();
    $old->forceFill(['deleted_at' => now()->subDays(40)])->saveQuietly();

    $recent = Task::factory()->create();
    $recent->delete();

    $this->artisan('workspace:prune-trashed')->assertSuccessful();

    expect(Task::withTrashed()->find($old->id))->toBeNull()
        ->and(Task::withTrashed()->find($recent->id))->not->toBeNull();
});

it('does not delete anything in --pretend mode', function () {
    Config::set('project-management.trash_ttl_days', 30);

    $old = Task::factory()->create();
    $old->delete();
    $old->forceFill(['deleted_at' => now()->subDays(40)])->saveQuietly();

    $this->artisan('workspace:prune-trashed --pretend')
        ->expectsOutputToContain('Would prune')
        ->assertSuccessful();

    expect(Task::withTrashed()->find($old->id))->not->toBeNull();
});

it('prunes old trashed task comments', function () {
    Config::set('project-management.trash_ttl_days', 30);

    $old = TaskComment::factory()->create();
    $old->delete();
    $old->forceFill(['deleted_at' => now()->subDays(40)])->saveQuietly();

    $recent = TaskComment::factory()->create();
    $recent->delete();

    $this->artisan('workspace:prune-trashed')->assertSuccessful();

    expect(TaskComment::withTrashed()->find($old->id))->toBeNull()
        ->and(TaskComment::withTrashed()->find($recent->id))->not->toBeNull();
});
