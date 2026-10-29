<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Notifications\AddedToProjectTeam;
use App\Notifications\MadeProjectManager;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->create(['name' => 'Asha Admin']);
    Member::factory()->linkedTo($this->admin)->create(['organization_id' => $this->organization->id, 'is_office_admin' => true]);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'SMS Gateway']);
    $this->team = $this->project->ensureTeam();

    $this->personUser = User::factory()->create();
    $this->person = Member::factory()->linkedTo($this->personUser)->create(['organization_id' => $this->organization->id, 'name' => 'Bikash Dev']);
});

function teamEntries(Project $project): array
{
    return ProjectActivity::query()->where('project_id', $project->id)->whereNull('task_id')->orderBy('id')->pluck('description')->all();
}

it('tells a person they joined a project\'s team, and records who added them', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post("/workspace/teams/{$this->team->id}/members", ['member_id' => $this->person->id])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->personUser, AddedToProjectTeam::class, fn (AddedToProjectTeam $n) => $n->toArray($this->personUser)['url'] === "/workspace/projects/{$this->project->slug}?view=people"
        && $n->toArray($this->personUser)['body'] === 'Asha Admin added you to the SMS Gateway team.');

    expect(teamEntries($this->project))->toBe(['Added Bikash Dev to the SMS Gateway team'])
        ->and(ProjectActivity::query()->where('project_id', $this->project->id)->value('user_id'))->toBe($this->admin->id);
});

it('tells a person they now manage a project, and records the hand-over', function () {
    $old = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Old Manager']);
    $this->team->members()->attach($old->id, ['role' => 'leader']);
    $this->team->members()->attach($this->person->id);
    Notification::fake();

    $this->actingAs($this->admin)
        ->patch("/workspace/teams/{$this->team->id}/members/{$this->person->id}", ['role' => 'leader'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->personUser, MadeProjectManager::class);

    expect(array_slice(teamEntries($this->project), -2))->toBe([
        'Old Manager no longer manages SMS Gateway',
        'Made Bikash Dev manager of SMS Gateway',
    ]);
});

it('tells the manager of a new project, and records removals', function () {
    Notification::fake();
    $this->team->members()->attach($this->person->id);

    $this->actingAs($this->admin)->post('/workspace/projects', ['title' => 'Nagarik App', 'manager_id' => $this->person->id])->assertSessionHasNoErrors();
    Notification::assertSentTo($this->personUser, MadeProjectManager::class, fn (MadeProjectManager $n) => $n->project->title === 'Nagarik App');

    $this->actingAs($this->admin)->delete("/workspace/teams/{$this->team->id}/members/{$this->person->id}")->assertSessionHasNoErrors();

    expect(teamEntries($this->project))->toBe([
        'Added Bikash Dev to the SMS Gateway team',
        'Removed Bikash Dev from the SMS Gateway team',
    ]);
});

it('never notifies people about changes they made themselves', function () {
    Notification::fake();
    $adminMember = Member::query()->where('user_id', $this->admin->id)->firstOrFail();

    $this->actingAs($this->admin)->post('/workspace/projects', ['title' => 'GIOMS', 'manager_id' => $adminMember->id])->assertSessionHasNoErrors();

    Notification::assertNothingSentTo($this->admin);
});

it('shows team changes on the project\'s progress and in the feed, only to people who see the project', function () {
    $this->actingAs($this->admin)->post("/workspace/teams/{$this->team->id}/members", ['member_id' => $this->person->id]);

    $this->actingAs($this->admin)->get("/workspace/projects/{$this->project->slug}/progress")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('progress.activity.0.description', 'Added Bikash Dev to the SMS Gateway team')
            ->where('progress.activity.0.project_slug', $this->project->slug));

    $stranger = User::factory()->create();
    Member::factory()->linkedTo($stranger)->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($this->personUser)->getJson('/api/v1/workspace/dashboard')->assertOk()
        ->assertJsonFragment(['description' => 'Added Bikash Dev to the SMS Gateway team']);
    $this->actingAs($stranger)->getJson('/api/v1/workspace/dashboard')->assertOk()
        ->assertJsonMissing(['description' => 'Added Bikash Dev to the SMS Gateway team']);
});

it('lets the office admin publish a project, but not its manager', function () {
    $managerUser = User::factory()->create();
    $this->team->members()->attach(Member::factory()->linkedTo($managerUser)->create(['organization_id' => $this->organization->id])->id, ['role' => 'leader']);

    $this->actingAs($this->admin)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('project.can_publish', true));
    $this->actingAs($managerUser)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('project.can_publish', false));
});
