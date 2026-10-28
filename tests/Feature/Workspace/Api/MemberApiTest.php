<?php

use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

function memberApiLeaderOf(Team $team): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team->members()->attach($member->id, ['role' => 'leader']);
    Sanctum::actingAs($user, ['*']);

    return $user;
}

it('lets a super-admin delete a member', function () {
    Sanctum::actingAs(User::factory()->create(['email' => 'boss@example.com']), ['*']);
    $victim = Member::factory()->create();

    $this->deleteJson("/api/v1/workspace/members/{$victim->id}")->assertOk();

    expect(Member::query()->find($victim->id))->toBeNull();
});

it('forbids a leader from deleting a member globally', function () {
    $team = Team::factory()->create();
    memberApiLeaderOf($team);
    $victim = Member::factory()->create();
    $team->members()->attach($victim->id);

    $this->deleteJson("/api/v1/workspace/members/{$victim->id}")->assertForbidden();

    expect(Member::query()->find($victim->id))->not->toBeNull();
});

it('forbids a leader from creating people, even for their own team', function () {
    $team = Team::factory()->create();
    memberApiLeaderOf($team);

    Mail::fake();

    $this->postJson('/api/v1/workspace/members', [
        'name' => 'New Hire',
        'email' => 'new.hire@example.com',
        'team_ids' => [$team->id],
    ])->assertForbidden();

    expect(Member::query()->where('name', 'New Hire')->exists())->toBeFalse();
});

it('lets an office admin create a member in their organization', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    Member::factory()->linkedTo($admin)->create(['is_office_admin' => true, 'organization_id' => $team->organization_id]);
    Sanctum::actingAs($admin, ['*']);

    Mail::fake();

    $this->postJson('/api/v1/workspace/members', [
        'name' => 'New Hire',
        'email' => 'new.hire@example.com',
        'team_ids' => [$team->id],
    ])->assertCreated()
        ->assertJsonStructure(['message', 'data' => ['id', 'name']])
        ->assertJsonPath('data.organization_id', $team->organization_id);
});

it('forbids a leader from creating a member for a team they do not lead', function () {
    $own = Team::factory()->create();
    memberApiLeaderOf($own);
    $foreign = Team::factory()->create();

    $this->postJson('/api/v1/workspace/members', [
        'name' => 'Wrong Team',
        'team_ids' => [$foreign->id],
    ])->assertForbidden();

    expect(Member::query()->where('name', 'Wrong Team')->exists())->toBeFalse();
});

it('forbids a leader from password-resetting a super-admin member (privilege-escalation block)', function () {
    $team = Team::factory()->create();
    memberApiLeaderOf($team);

    $bossUser = User::factory()->create(['email' => 'boss@example.com']);
    $bossMember = Member::factory()->linkedTo($bossUser)->create();
    $team->members()->attach($bossMember->id);

    $this->patchJson("/api/v1/workspace/members/{$bossMember->id}", ['password' => 'hijacked-pass-1'])
        ->assertForbidden();

    expect(Hash::check('hijacked-pass-1', $bossUser->fresh()->password))->toBeFalse();
});

it('forbids a leader from editing people on their team (office admins do that)', function () {
    $team = Team::factory()->create();
    memberApiLeaderOf($team);
    $member = Member::factory()->create();
    $team->members()->attach($member->id);

    $this->patchJson("/api/v1/workspace/members/{$member->id}", ['name' => 'Renamed'])
        ->assertForbidden();

    expect($member->fresh()->name)->not->toBe('Renamed');
});

it('forbids a leader from reassigning a member\'s teams via member update', function () {
    $own = Team::factory()->create();
    memberApiLeaderOf($own);
    $foreign = Team::factory()->create();

    $member = Member::factory()->create();
    $own->members()->attach($member->id);

    $this->patchJson("/api/v1/workspace/members/{$member->id}", [
        'name' => 'Renamed',
        'team_ids' => [$own->id, $foreign->id],
    ])->assertForbidden();

    expect($member->fresh()->teams()->pluck('teams.id')->all())->toBe([$own->id]);
});
