<?php

use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

it('keeps task notes and contacts off for a new user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('features.task_notes', false)
            ->where('features.contacts', false));
});

it('turns task notes and contacts on from settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/workspace/preferences', [
        'task_notes_enabled' => true,
        'contacts_enabled' => true,
    ])->assertRedirect();

    $this->actingAs($user->fresh())
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('features.task_notes', true)
            ->where('features.contacts', true));
});

it('keeps the other settings when one feature is switched', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['theme' => 'dark', 'contacts_enabled' => true]);

    $this->actingAs($user)->patch('/workspace/preferences', ['task_notes_enabled' => true])
        ->assertRedirect();

    $this->actingAs($user->fresh())
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('appearance.theme', 'dark')
            ->where('features.contacts', true)
            ->where('features.task_notes', true));
});

it('rejects a feature switch that is not a boolean', function () {
    $this->actingAs(User::factory()->create())
        ->patch('/workspace/preferences', ['contacts_enabled' => 'sometimes'])
        ->assertSessionHasErrors('contacts_enabled');
});

it('shares no task notes while task notes are off', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['project_id' => Project::factory()->create()->id]);
    ProjectNote::create(['task_id' => $task->id, 'user_id' => $user->id, 'type' => 'general', 'body' => 'Mine']);

    $this->actingAs($user)
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taskNotes', fn (Collection $notes): bool => $notes->isEmpty()));
});

it('shares the task notes once task notes are on', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['task_notes_enabled' => true]);
    $task = Task::factory()->create(['project_id' => Project::factory()->create()->id]);
    ProjectNote::create(['task_id' => $task->id, 'user_id' => $user->id, 'type' => 'general', 'body' => 'Mine']);

    $this->actingAs($user)
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taskNotes', fn (Collection $notes): bool => $notes->pluck('body')->all() === ['Mine']));
});

it('reads and switches the features over the API', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/preferences')
        ->assertSuccessful()
        ->assertJsonPath('data.task_notes_enabled', false)
        ->assertJsonPath('data.contacts_enabled', false);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/user/preferences', ['contacts_enabled' => true])
        ->assertSuccessful()
        ->assertJsonPath('data.contacts_enabled', true)
        ->assertJsonPath('data.task_notes_enabled', false);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/user/preferences', ['task_notes_enabled' => 'yes please'])
        ->assertStatus(422);
});

it('names the features in the API user payload', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['task_notes_enabled' => true]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/user')
        ->assertSuccessful()
        ->assertJsonPath('features.task_notes', true)
        ->assertJsonPath('features.contacts', false);
});
