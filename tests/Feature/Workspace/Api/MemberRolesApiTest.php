<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Notifications\MadeProgramManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * The API twin of MemberRolesTest: the same FormRequests and service, so
 * the same rules, answered as JSON.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    Mail::fake();

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create(['name' => 'Elsewhere']);

    $this->admin = User::factory()->create();
    Member::factory()->linkedTo($this->admin)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->jointSecretary = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $this->program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jointSecretary->id, 'name' => 'Revenue']);

    $this->projectA = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Tax Portal']);
    $this->teamA = $this->projectA->ensureTeam();
    $this->underSecretary = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $this->teamA->members()->attach($this->underSecretary->id, ['role' => 'leader']);

    $this->projectB = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Customs']);
    $this->teamB = $this->projectB->ensureTeam();
    $this->person = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);

    $this->projectY = Project::factory()->create(['organization_id' => $this->otherOrg->id, 'title' => 'Joint Desk']);
    $this->teamY = $this->projectY->ensureTeam();
    $this->teamY->update(['is_cross_organization' => true]);

    Notification::fake();
});

it('sets a person\'s roles through the API', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->patchJson("/api/v1/workspace/members/{$this->person->id}", [
        'managed_program_ids' => [$this->program->id],
        'managed_project_ids' => [$this->projectA->id],
        'team_ids' => [$this->teamB->id],
    ])->assertOk()
        ->assertJsonPath('message', 'Member updated. Roles saved.')
        ->json('data');

    $teamIds = $data['team_ids'];
    sort($teamIds);
    $expected = [$this->teamA->id, $this->teamB->id];
    sort($expected);

    expect($data['managed_program_ids'])->toBe([$this->program->id])
        ->and($data['managed_project_ids'])->toBe([$this->projectA->id])
        ->and($data['led_team_ids'])->toBe([$this->teamA->id])
        ->and($teamIds)->toBe($expected)
        ->and($this->teamA->leaders()->pluck('members.id')->all())->toBe([$this->person->id]);

    Notification::assertSentTo($this->person->user, MadeProgramManager::class);
});

it('takes a project from its manager, but answers 422 when a program would be left without one', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->patchJson("/api/v1/workspace/members/{$this->underSecretary->id}", ['managed_project_ids' => []])
        ->assertOk()
        ->assertJsonPath('data.managed_project_ids', []);
    $this->patchJson("/api/v1/workspace/members/{$this->jointSecretary->id}", ['managed_program_ids' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors.managed_program_ids.0', 'Choose another manager for the Revenue program first.');

    expect($this->teamA->leaders()->count())->toBe(0)
        ->and($this->teamA->members()->pluck('members.id')->all())->toBe([$this->underSecretary->id])
        ->and($this->program->fresh()->manager_id)->toBe($this->jointSecretary->id);
});

it('answers 422 when an organizational admin reaches another organization\'s team', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->patchJson("/api/v1/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamY->id]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.team_ids.0', 'Joint Desk belongs to another organization.');

    expect($this->person->teams()->exists())->toBeFalse();
});

it('forbids the role fields to a project manager', function () {
    Sanctum::actingAs($this->underSecretary->user, ['*']);

    $this->patchJson("/api/v1/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamA->id]])
        ->assertForbidden();

    expect($this->person->teams()->exists())->toBeFalse();
});

it('creates a person with their roles through the API', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->postJson('/api/v1/workspace/members', [
        'name' => 'New Manager', 'email' => 'new.manager@example.com',
        'managed_project_ids' => [$this->projectB->id],
    ])->assertCreated()->json('data');

    expect($data['managed_project_ids'])->toBe([$this->projectB->id])
        ->and($data['managed_program_ids'])->toBe([])
        ->and($data['team_ids'])->toBe([$this->teamB->id])
        ->and($this->teamB->leaders()->pluck('members.id')->all())->toBe([$data['id']]);
});

it('lists the role options on the API People directory', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/v1/workspace/team')
        ->assertOk()
        ->assertJsonPath('canSetRoles', true)
        ->assertJsonPath('roleOptions.programs.0.name', 'Revenue')
        ->assertJsonPath('roleOptions.projects.1.title', 'Tax Portal')
        ->assertJsonPath('roleOptions.projects.1.manager.id', $this->underSecretary->id)
        ->assertJsonCount(2, 'roleOptions.projects');
});
