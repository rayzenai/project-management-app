<?php

use App\Mail\SetPasswordLink;
use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;

/*
 * The API twin of InvitePeopleTest: the same preview and the same rules for
 * sending, answered as JSON.
 */

beforeEach(function () {
    config([
        'project-management.super_admins' => ['boss@example.com'],
        'queue.default' => 'sync',
    ]);
    Mail::fake();
    Sleep::fake();
});

function inviteApiAdmin(Organization $organization): User
{
    $user = User::factory()->create(['email' => 'asha@example.com', 'name' => 'Asha Gurung']);
    Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => true]);
    Sanctum::actingAs($user, ['*']);

    return $user;
}

it('previews and invites as the web does', function () {
    $roads = Organization::factory()->create();
    inviteApiAdmin($roads);
    $officer = Designation::factory()->create(['organization_id' => $roads->id]);
    $mina = Member::factory()->create(['organization_id' => $roads->id, 'email' => 'mina@example.com']);

    $this->postJson('/api/v1/workspace/invites/preview', ['text' => "Deepak Karki, deepak@example.com\nPrakash Thapa\nMina, mina@example.com"])
        ->assertOk()
        ->assertExactJson([
            'rows' => [
                ['name' => 'Deepak Karki', 'email' => 'deepak@example.com', 'designation_id' => null, 'status' => 'ready', 'member_id' => null],
                ['name' => 'Prakash Thapa', 'email' => null, 'designation_id' => null, 'status' => 'needs_email', 'member_id' => null],
                ['name' => 'Mina', 'email' => 'mina@example.com', 'designation_id' => null, 'status' => 'ready', 'member_id' => $mina->id],
            ],
            'without_login_count' => 0,
        ]);

    $this->postJson('/api/v1/workspace/invites', ['people' => [
        ['name' => 'Deepak Karki', 'email' => 'deepak@example.com', 'designation_id' => $officer->id],
        ['name' => 'Prakash Thapa', 'email' => null],
        ['name' => 'Mina', 'email' => 'mina@example.com'],
    ]])
        ->assertOk()
        ->assertExactJson([
            'message' => 'Invited 2 people.',
            'data' => [
                'invited' => 2,
                'skipped' => [['name' => 'Prakash Thapa', 'email' => null, 'status' => 'needs_email']],
            ],
        ]);

    expect(Member::query()->where('email', 'deepak@example.com')->sole())
        ->organization_id->toBe($roads->id)
        ->designation_id->toBe($officer->id)
        ->and($mina->fresh()->user_id)->not->toBeNull();
    Mail::assertSent(SetPasswordLink::class, 2);
});

it('answers another organization\'s person as already here and changes nothing', function () {
    $roads = Organization::factory()->create();
    $health = Organization::factory()->create();
    inviteApiAdmin($roads);
    $kamala = Member::factory()->create(['organization_id' => $health->id, 'email' => 'kamala@health.example']);

    $this->postJson('/api/v1/workspace/invites/preview', ['people' => [['name' => 'K', 'email' => 'kamala@health.example']]])
        ->assertOk()
        ->assertJsonPath('rows.0.status', 'already_here')
        ->assertJsonPath('rows.0.member_id', null);

    $this->postJson('/api/v1/workspace/invites', ['people' => [['name' => 'K', 'email' => 'kamala@health.example', 'status' => 'ready', 'member_id' => $kamala->id]]])
        ->assertOk()
        ->assertJsonPath('data.invited', 0)
        ->assertJsonPath('data.skipped.0.status', 'already_here');

    expect($kamala->fresh()->user_id)->toBeNull()
        ->and(User::query()->where('email', 'kamala@health.example')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('adds the organization\'s people who have no login', function () {
    $roads = Organization::factory()->create();
    inviteApiAdmin($roads);
    Member::factory()->count(2)->create(['organization_id' => $roads->id]);

    $this->postJson('/api/v1/workspace/invites/preview', [])->assertOk()->assertJsonPath('without_login_count', 2);
    $this->postJson('/api/v1/workspace/invites', ['without_login' => true])->assertOk()->assertJsonPath('data.invited', 2);

    Mail::assertSent(SetPasswordLink::class, 2);
});

it('refuses more than 200 people at once, saying how many', function () {
    $roads = Organization::factory()->create();
    inviteApiAdmin($roads);
    Member::factory()->count(60)->create(['organization_id' => $roads->id]);
    $people = array_map(fn (int $i): array => ['name' => "Person {$i}", 'email' => "person{$i}@example.com"], range(1, 150));
    $refusal = ['message' => "That's 210 people, 210 to invite. Invite at most 200 at a time.", 'errors' => ['people' => "That's 210 people, 210 to invite. Invite at most 200 at a time."]];

    $this->postJson('/api/v1/workspace/invites/preview', ['people' => $people, 'without_login' => true])->assertStatus(422)->assertExactJson($refusal);
    $this->postJson('/api/v1/workspace/invites', ['people' => $people, 'without_login' => true])->assertStatus(422)->assertExactJson($refusal);
    $this->postJson('/api/v1/workspace/invites', ['people' => $people])->assertOk()->assertJsonPath('data.invited', 150);

    expect(User::query()->count())->toBe(151);
});

it('refuses a project manager and an admin of another organization', function () {
    $roads = Organization::factory()->create();
    $health = Organization::factory()->create();
    $team = Team::factory()->create(['organization_id' => $roads->id]);
    $manager = User::factory()->create();
    $team->members()->attach(Member::factory()->linkedTo($manager)->create(['organization_id' => $roads->id])->id, ['role' => 'leader']);
    $people = ['people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']]];

    Sanctum::actingAs($manager, ['*']);
    $this->postJson('/api/v1/workspace/invites/preview', $people)->assertForbidden();
    $this->postJson('/api/v1/workspace/invites', $people)->assertForbidden();

    inviteApiAdmin($roads);
    $this->postJson('/api/v1/workspace/invites/preview', [...$people, 'organization_id' => $health->id])->assertForbidden();
    $this->postJson('/api/v1/workspace/invites', [...$people, 'organization_id' => $health->id])->assertForbidden();

    expect(User::query()->where('email', 'deepak@example.com')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('makes a super-admin name the organization, and takes at most 200 people', function () {
    $roads = Organization::factory()->create();
    Sanctum::actingAs(User::factory()->create(['email' => 'boss@example.com']), ['*']);
    $people = array_map(fn (int $i): array => ['name' => "Person {$i}", 'email' => "person{$i}@example.com"], range(1, 201));

    $this->postJson('/api/v1/workspace/invites', ['people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']]])
        ->assertJsonValidationErrors('organization_id');
    $this->postJson('/api/v1/workspace/invites', ['organization_id' => $roads->id, 'people' => $people])
        ->assertJsonValidationErrors('people');
    $this->postJson('/api/v1/workspace/invites/preview', ['organization_id' => $roads->id, 'text' => str_repeat('a', 20001)])
        ->assertJsonValidationErrors('text');

    expect(User::query()->count())->toBe(1);

    $this->postJson('/api/v1/workspace/invites', ['organization_id' => $roads->id, 'people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']]])
        ->assertOk()
        ->assertJsonPath('message', 'Invited 1 person.');
    Mail::assertSent(SetPasswordLink::class, 1);
});
