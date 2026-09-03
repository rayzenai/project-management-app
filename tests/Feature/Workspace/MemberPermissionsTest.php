<?php

use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

function actAsLeaderOf(Team $team): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team->members()->attach($member->id, ['role' => 'leader']);

    test()->actingAs($user);

    return $user;
}

it('lets a leader create a member for their own team', function () {
    $team = Team::factory()->create();
    actAsLeaderOf($team);

    $this->post('/workspace/members', [
        'name' => 'New Hire',
        'team_ids' => [$team->id],
    ])->assertRedirect();

    expect(Member::query()->where('name', 'New Hire')->exists())->toBeTrue();
});

it('forbids a leader creating a member for a team they do not lead', function () {
    $own = Team::factory()->create();
    $foreign = Team::factory()->create();
    actAsLeaderOf($own);

    $this->post('/workspace/members', [
        'name' => 'Wrong Team',
        'team_ids' => [$foreign->id],
    ])->assertForbidden();

    expect(Member::query()->where('name', 'Wrong Team')->exists())->toBeFalse();
});

it('forbids a leader from deleting a member globally', function () {
    $team = Team::factory()->create();
    actAsLeaderOf($team);
    $victim = Member::factory()->create();
    $team->members()->attach($victim->id);

    $this->delete("/workspace/members/{$victim->id}")->assertForbidden();

    expect(Member::query()->find($victim->id))->not->toBeNull();
});

it('lets a super-admin delete a member globally', function () {
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));
    $victim = Member::factory()->create();

    $this->delete("/workspace/members/{$victim->id}")->assertRedirect();

    expect(Member::query()->find($victim->id))->toBeNull();
});

it('forbids a leader from password-resetting a member whose login is a super-admin', function () {
    $team = Team::factory()->create();
    actAsLeaderOf($team);

    $bossUser = User::factory()->create(['email' => 'boss@example.com']);
    $bossMember = Member::factory()->linkedTo($bossUser)->create();
    $team->members()->attach($bossMember->id);

    $this->patch("/workspace/members/{$bossMember->id}", ['password' => 'hijacked-pass-1'])
        ->assertForbidden();

    expect(Hash::check('hijacked-pass-1', $bossUser->fresh()->password))->toBeFalse();
});

it('forbids a leader from reassigning a member\'s teams via member update', function () {
    $own = Team::factory()->create();
    $foreign = Team::factory()->create();
    actAsLeaderOf($own);

    $member = Member::factory()->create();
    $own->members()->attach($member->id);

    $this->patch("/workspace/members/{$member->id}", [
        'name' => 'Renamed',
        'team_ids' => [$own->id, $foreign->id],
    ])->assertForbidden();

    expect($member->fresh()->teams()->pluck('teams.id')->all())->toBe([$own->id]);
});
