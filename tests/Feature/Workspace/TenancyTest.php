<?php

use App\Mail\LoginDetails;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Notifications\MentionedInComment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * Organizations are the tenant boundary: what each role sees and may change
 * inside its own organization, and that nothing leaks across, except through a
 * team a super-admin made cross-organization.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    Mail::fake();

    $this->orgA = Organization::factory()->create(['name' => 'Ministry A']);
    $this->orgB = Organization::factory()->create(['name' => 'Ministry B']);
});

function tenancyPerson(Organization $organization, array $attributes = []): User
{
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id] + $attributes);

    return $user;
}

function tenancyOfficeAdmin(Organization $organization): User
{
    return tenancyPerson($organization, ['is_office_admin' => true]);
}

function tenancyMemberOf(User $user): Member
{
    return Member::query()->where('user_id', $user->id)->firstOrFail();
}

function tenancyTeam(Organization $organization, array $attributes = []): Team
{
    return Team::factory()->create(['organization_id' => $organization->id] + $attributes);
}

function tenancyBoss(): User
{
    return User::factory()->create(['email' => 'boss@example.com']);
}

// ---- Seeing ----

it('shows an office admin every project of their organization and none of another', function () {
    $admin = tenancyOfficeAdmin($this->orgA);
    $ownPrivate = Project::factory()->create(['organization_id' => $this->orgA->id]);
    $ownPrivate->teams()->attach(tenancyTeam($this->orgA)->id);
    $foreign = Project::factory()->create(['organization_id' => $this->orgB->id, 'is_public' => true]);

    $visible = Project::query()->visibleTo($admin)->pluck('id');

    expect($visible)->toContain($ownPrivate->id)
        ->and($visible)->not->toContain($foreign->id);

    $this->actingAs($admin)->get("/workspace/projects/{$ownPrivate->slug}")->assertOk();
    $this->actingAs($admin)->get("/workspace/projects/{$foreign->slug}")->assertForbidden();
});

it('keeps a public project inside its own organization', function () {
    $public = Project::factory()->create(['organization_id' => $this->orgA->id, 'is_public' => true]);

    $this->actingAs(tenancyPerson($this->orgA))->get("/workspace/projects/{$public->slug}")->assertOk();
    $this->actingAs(tenancyPerson($this->orgB))->get("/workspace/projects/{$public->slug}")->assertForbidden();
});

it('shows a leader only their own teams and teammates', function () {
    $leader = tenancyPerson($this->orgA);
    $team = tenancyTeam($this->orgA, ['name' => 'Mine']);
    $team->members()->attach(tenancyMemberOf($leader)->id, ['role' => 'leader']);
    $mate = Member::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Teammate']);
    $team->members()->attach($mate->id);

    $other = tenancyTeam($this->orgA, ['name' => 'Other']);
    $stranger = Member::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Same Office Stranger']);
    $other->members()->attach($stranger->id);

    $this->actingAs($leader)->get('/workspace/team')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Team/Index')
            ->has('teams', 1)
            ->where('teams.0.name', 'Mine')
            ->where('members', fn ($members) => collect($members)->pluck('name')->sort()->values()->all() === collect([$mate->name, tenancyMemberOf($leader)->name])->sort()->values()->all()));
});

it('shows an office admin all of their organization, plus outsiders on its cross-organization teams', function () {
    $admin = tenancyOfficeAdmin($this->orgA);
    $teamA = tenancyTeam($this->orgA);
    $crossTeam = tenancyTeam($this->orgA, ['is_cross_organization' => true]);
    $colleague = Member::factory()->create(['organization_id' => $this->orgA->id]);
    $guest = Member::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Guest From B']);
    $crossTeam->members()->attach($guest->id);
    $hidden = Member::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Hidden In B']);
    $teamB = tenancyTeam($this->orgB);

    $people = Member::query()->visibleTo($admin)->pluck('id');
    $teams = Team::query()->visibleTo($admin)->pluck('id');

    expect($people)->toContain($colleague->id, $guest->id)
        ->and($people)->not->toContain($hidden->id)
        ->and($teams)->toContain($teamA->id, $crossTeam->id)
        ->and($teams)->not->toContain($teamB->id);
});

it('limits the people shared with every page to those the user may see', function () {
    $person = tenancyPerson($this->orgA);
    Member::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Far Away']);
    Member::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Not On My Team']);

    $this->actingAs($person)->get('/workspace/team')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quickAddContext.team', fn ($team) => collect($team)->pluck('id')->all() === [tenancyMemberOf($person)->id]));
});

it('lets a super-admin see everything and narrow the team page to one organization', function () {
    tenancyTeam($this->orgA, ['name' => 'Alpha']);
    tenancyTeam($this->orgB, ['name' => 'Beta']);

    $this->actingAs(tenancyBoss())->get('/workspace/team?organization='.$this->orgB->id)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('teams', 1)
            ->where('teams.0.name', 'Beta')
            ->has('organizations', 2));
});

// ---- Teams ----

it('keeps an office admin\'s new team in their own organization and never cross-organization', function () {
    $this->actingAs(tenancyOfficeAdmin($this->orgA))
        ->post('/workspace/teams', ['name' => 'Desk', 'organization_id' => $this->orgB->id, 'is_cross_organization' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $team = Team::query()->where('name', 'Desk')->firstOrFail();

    expect($team->organization_id)->toBe($this->orgA->id)
        ->and($team->is_cross_organization)->toBeFalse();
});

it('forbids an office admin from touching another organization\'s team', function () {
    $foreign = tenancyTeam($this->orgB, ['name' => 'Theirs']);
    $this->actingAs(tenancyOfficeAdmin($this->orgA));

    $this->patch("/workspace/teams/{$foreign->id}", ['name' => 'Mine now'])->assertForbidden();
    $this->delete("/workspace/teams/{$foreign->id}")->assertForbidden();

    expect($foreign->fresh()->name)->toBe('Theirs');
});

it('lets only a super-admin switch a team to cross-organization', function () {
    $team = tenancyTeam($this->orgA);

    $this->actingAs(tenancyOfficeAdmin($this->orgA))
        ->patch("/workspace/teams/{$team->id}", ['is_cross_organization' => true]);
    expect($team->fresh()->is_cross_organization)->toBeFalse();

    $this->actingAs(tenancyBoss())
        ->patch("/workspace/teams/{$team->id}", ['is_cross_organization' => true])
        ->assertSessionHasNoErrors();
    expect($team->fresh()->is_cross_organization)->toBeTrue();
});

it('will not switch cross-organization off while outsiders are on the team', function () {
    $team = tenancyTeam($this->orgA, ['is_cross_organization' => true]);
    $team->members()->attach(Member::factory()->create(['organization_id' => $this->orgB->id])->id);

    $this->actingAs(tenancyBoss())
        ->patch("/workspace/teams/{$team->id}", ['is_cross_organization' => false])
        ->assertSessionHasErrors('is_cross_organization');

    expect($team->fresh()->is_cross_organization)->toBeTrue();
});

// ---- Rosters ----

it('finds candidates only in the team\'s organization for an ordinary team', function () {
    $leader = tenancyPerson($this->orgA);
    $team = tenancyTeam($this->orgA);
    $team->members()->attach(tenancyMemberOf($leader)->id, ['role' => 'leader']);
    $already = Member::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Sita Already']);
    $team->members()->attach($already->id);
    $colleague = Member::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Sita Colleague']);
    Member::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Sita Elsewhere']);
    Member::factory()->inactive()->create(['organization_id' => $this->orgA->id, 'name' => 'Sita Inactive']);

    $this->actingAs($leader)->getJson("/workspace/teams/{$team->id}/candidates?q=sita")
        ->assertOk()
        ->assertJsonPath('data', [[
            'id' => $colleague->id,
            'name' => 'Sita Colleague',
            'email' => $colleague->email,
            'title' => $colleague->title,
            'organization' => 'Ministry A',
        ]]);
});

it('finds candidates in every organization for a cross-organization team', function () {
    $leader = tenancyPerson($this->orgA);
    $team = tenancyTeam($this->orgA, ['is_cross_organization' => true]);
    $team->members()->attach(tenancyMemberOf($leader)->id, ['role' => 'leader']);
    $outsider = Member::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Hari From B']);

    $this->actingAs($leader)->getJson("/workspace/teams/{$team->id}/candidates?q=hari")
        ->assertOk()
        ->assertJsonPath('data.0.id', $outsider->id)
        ->assertJsonPath('data.0.organization', 'Ministry B');

    $this->post("/workspace/teams/{$team->id}/members", ['member_id' => $outsider->id])->assertSessionHasNoErrors();

    expect($team->members()->whereKey($outsider->id)->exists())->toBeTrue();
});

it('refuses the candidate search to anyone who cannot manage the roster', function () {
    $team = tenancyTeam($this->orgA);
    $member = tenancyPerson($this->orgA);
    $team->members()->attach(tenancyMemberOf($member)->id);

    $this->actingAs($member)->getJson("/workspace/teams/{$team->id}/candidates?q=si")->assertForbidden();
    $this->actingAs(tenancyOfficeAdmin($this->orgB))->getJson("/workspace/teams/{$team->id}/candidates?q=si")->assertForbidden();
});

it('lets a leader take a member off the team but not another leader', function () {
    $leader = tenancyPerson($this->orgA);
    $team = tenancyTeam($this->orgA);
    $team->members()->attach(tenancyMemberOf($leader)->id, ['role' => 'leader']);
    $coLeader = Member::factory()->create(['organization_id' => $this->orgA->id]);
    $plain = Member::factory()->create(['organization_id' => $this->orgA->id]);
    $team->members()->attach([$coLeader->id => ['role' => 'leader'], $plain->id => ['role' => 'member']]);

    $this->actingAs($leader);
    $this->delete("/workspace/teams/{$team->id}/members/{$coLeader->id}")->assertForbidden();
    $this->delete("/workspace/teams/{$team->id}/members/{$plain->id}")->assertRedirect();

    $this->actingAs(tenancyOfficeAdmin($this->orgA))
        ->delete("/workspace/teams/{$team->id}/members/{$coLeader->id}")->assertRedirect();

    expect($team->members()->pluck('members.id')->all())->toBe([tenancyMemberOf($leader)->id]);
});

it('lets an office admin choose leaders only in their own organization', function () {
    $teamA = tenancyTeam($this->orgA);
    $teamB = tenancyTeam($this->orgB);
    $a = Member::factory()->create(['organization_id' => $this->orgA->id]);
    $b = Member::factory()->create(['organization_id' => $this->orgB->id]);
    $teamA->members()->attach($a->id);
    $teamB->members()->attach($b->id);

    $this->actingAs(tenancyOfficeAdmin($this->orgA));
    $this->patch("/workspace/teams/{$teamA->id}/members/{$a->id}", ['role' => 'leader'])->assertRedirect();
    $this->patch("/workspace/teams/{$teamB->id}/members/{$b->id}", ['role' => 'leader'])->assertForbidden();

    expect($teamA->leaders()->whereKey($a->id)->exists())->toBeTrue()
        ->and($teamB->leaders()->whereKey($b->id)->exists())->toBeFalse();
});

// ---- People ----

it('creates an office admin\'s new person in their organization, never as an office admin', function () {
    $this->actingAs(tenancyOfficeAdmin($this->orgA))
        ->post('/workspace/members', [
            'name' => 'New Hire', 'email' => 'hire@example.com', 'password' => 'Kp7m-Qx4r-Tz9a',
            'organization_id' => $this->orgB->id, 'is_office_admin' => true,
        ])
        ->assertSessionHasNoErrors();

    $member = Member::query()->where('email', 'hire@example.com')->firstOrFail();

    expect($member->organization_id)->toBe($this->orgA->id)
        ->and($member->is_office_admin)->toBeFalse();
    Mail::assertSent(LoginDetails::class);
});

it('lets a super-admin create an organization\'s office admin', function () {
    $this->actingAs(tenancyBoss())
        ->post('/workspace/members', [
            'name' => 'Chief', 'email' => 'chief@example.com', 'password' => 'Kp7m-Qx4r-Tz9a',
            'organization_id' => $this->orgB->id, 'is_office_admin' => true,
        ])
        ->assertSessionHasNoErrors();

    $chief = Member::query()->where('email', 'chief@example.com')->firstOrFail();

    expect($chief->organization_id)->toBe($this->orgB->id)
        ->and($chief->is_office_admin)->toBeTrue();
});

it('refuses an office admin a team of another organization for a new person', function () {
    $foreignTeam = tenancyTeam($this->orgB);

    $this->actingAs(tenancyOfficeAdmin($this->orgA))
        ->post('/workspace/members', [
            'name' => 'New Hire', 'email' => 'hire@example.com', 'password' => 'Kp7m-Qx4r-Tz9a',
            'team_ids' => [$foreignTeam->id],
        ])
        ->assertSessionHasErrors('team_ids');

    expect(Member::query()->where('email', 'hire@example.com')->exists())->toBeFalse();
});

it('keeps an office admin away from other organizations\' people, fellow office admins and super-admins', function () {
    $admin = tenancyOfficeAdmin($this->orgA);
    $foreigner = Member::factory()->create(['organization_id' => $this->orgB->id]);
    $peerUser = tenancyOfficeAdmin($this->orgA);
    $boss = tenancyBoss();
    $bossMember = Member::factory()->linkedTo($boss)->create(['organization_id' => $this->orgA->id]);
    $colleague = Member::factory()->create(['organization_id' => $this->orgA->id]);

    $this->actingAs($admin);
    $this->patch("/workspace/members/{$foreigner->id}", ['name' => 'Hijacked'])->assertForbidden();
    $this->patch('/workspace/members/'.tenancyMemberOf($peerUser)->id, ['password' => 'hijacked-pass-1'])->assertForbidden();
    $this->patch("/workspace/members/{$bossMember->id}", ['password' => 'hijacked-pass-1'])->assertForbidden();
    $this->delete("/workspace/members/{$foreigner->id}")->assertForbidden();
    $this->patch("/workspace/members/{$colleague->id}", ['name' => 'Renamed'])->assertRedirect();

    expect($foreigner->fresh()->name)->not->toBe('Hijacked')
        ->and($colleague->fresh()->name)->toBe('Renamed');
});

it('forbids an office admin from changing anyone\'s organization or admin status', function () {
    $colleague = Member::factory()->create(['organization_id' => $this->orgA->id]);
    $this->actingAs(tenancyOfficeAdmin($this->orgA));

    $this->patch("/workspace/members/{$colleague->id}", ['is_office_admin' => true])->assertForbidden();
    $this->patch("/workspace/members/{$colleague->id}", ['organization_id' => $this->orgB->id])->assertForbidden();

    expect($colleague->fresh()->is_office_admin)->toBeFalse()
        ->and($colleague->fresh()->organization_id)->toBe($this->orgA->id);
});

it('will not move a person to another organization while they sit on its teams', function () {
    $person = Member::factory()->create(['organization_id' => $this->orgA->id]);
    tenancyTeam($this->orgA, ['name' => 'Desk A'])->members()->attach($person->id);

    $this->actingAs(tenancyBoss())
        ->patch("/workspace/members/{$person->id}", ['organization_id' => $this->orgB->id])
        ->assertSessionHasErrors('organization_id');

    expect($person->fresh()->organization_id)->toBe($this->orgA->id);
});

it('takes the office admin\'s powers away while they are deactivated', function () {
    $admin = tenancyOfficeAdmin($this->orgA);
    tenancyMemberOf($admin)->update(['is_active' => false]);

    $this->actingAs($admin)->post('/workspace/teams', ['name' => 'Ghost'])->assertForbidden();
});

// ---- Projects ----

it('puts a leader\'s new project in their teams\' organization, unpublished', function () {
    $leader = tenancyPerson($this->orgA);
    $team = tenancyTeam($this->orgA);
    $team->members()->attach(tenancyMemberOf($leader)->id, ['role' => 'leader']);

    $this->actingAs($leader)->post('/workspace/projects', [
        'title' => 'Leader Project', 'team_ids' => [$team->id], 'is_public' => true, 'organization_id' => $this->orgB->id,
    ])->assertSessionHasNoErrors();

    $project = Project::query()->where('title', 'Leader Project')->firstOrFail();

    expect($project->organization_id)->toBe($this->orgA->id)
        ->and($project->is_public)->toBeFalse();
});

it('lets an office admin publish within their organization but not borrow another\'s team', function () {
    $admin = tenancyOfficeAdmin($this->orgA);
    $foreignTeam = tenancyTeam($this->orgB);

    $this->actingAs($admin)->post('/workspace/projects', ['title' => 'Notice Board', 'is_public' => true])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/workspace/projects', ['title' => 'Borrowed', 'team_ids' => [$foreignTeam->id]])
        ->assertSessionHasErrors('team_ids');

    expect(Project::query()->where('title', 'Notice Board')->firstOrFail())
        ->organization_id->toBe($this->orgA->id)
        ->is_public->toBeTrue()
        ->and(Project::query()->where('title', 'Borrowed')->exists())->toBeFalse();
});

it('makes a super-admin name the organization and match its teams', function () {
    $teamB = tenancyTeam($this->orgB);
    $this->actingAs(tenancyBoss());

    $this->post('/workspace/projects', ['title' => 'Nowhere', 'team_ids' => [$teamB->id]])
        ->assertSessionHasErrors('organization_id');
    $this->post('/workspace/projects', ['title' => 'Mismatch', 'team_ids' => [$teamB->id], 'organization_id' => $this->orgA->id])
        ->assertSessionHasErrors('team_ids');
    $this->post('/workspace/projects', ['title' => 'Right', 'team_ids' => [$teamB->id], 'organization_id' => $this->orgB->id])
        ->assertSessionHasNoErrors();

    expect(Project::query()->where('title', 'Right')->firstOrFail()->organization_id)->toBe($this->orgB->id);
});

// ---- Crossing over ----

it('shows a cross-organization outsider only the projects of their team', function () {
    $outsider = tenancyPerson($this->orgB);
    $taskForce = tenancyTeam($this->orgA, ['is_cross_organization' => true]);
    $taskForce->members()->attach(tenancyMemberOf($outsider)->id);

    $shared = Project::factory()->create(['organization_id' => $this->orgA->id]);
    $shared->teams()->attach($taskForce->id);
    $publicA = Project::factory()->create(['organization_id' => $this->orgA->id, 'is_public' => true]);
    $privateA = Project::factory()->create(['organization_id' => $this->orgA->id]);
    $privateA->teams()->attach(tenancyTeam($this->orgA)->id);

    $visible = Project::query()->visibleTo($outsider)->pluck('id');

    expect($visible)->toContain($shared->id)
        ->and($visible)->not->toContain($publicA->id)
        ->and($visible)->not->toContain($privateA->id);
});

it('never notifies a mentioned person who cannot see the task', function () {
    Notification::fake();
    $author = tenancyPerson($this->orgA);
    $team = tenancyTeam($this->orgA);
    $team->members()->attach(tenancyMemberOf($author)->id);
    $project = Project::factory()->create(['organization_id' => $this->orgA->id]);
    $project->teams()->attach($team->id);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $insider = tenancyPerson($this->orgA);
    $team->members()->attach(tenancyMemberOf($insider)->id);
    $outsider = tenancyPerson($this->orgB);

    $body = sprintf(
        'Please check @[In](member:%d) and @[Out](member:%d)',
        tenancyMemberOf($insider)->id,
        tenancyMemberOf($outsider)->id,
    );

    $this->actingAs($author)->post("/workspace/tasks/{$task->id}/comments", ['body' => $body])->assertSessionHasNoErrors();

    Notification::assertSentTo($insider, MentionedInComment::class);
    Notification::assertNotSentTo($outsider, MentionedInComment::class);
});

it('applies the same organization rules over the API', function () {
    $leader = tenancyPerson($this->orgA);
    $team = tenancyTeam($this->orgA);
    $team->members()->attach(tenancyMemberOf($leader)->id, ['role' => 'leader']);
    $outsider = Member::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Remote']);
    Sanctum::actingAs($leader, ['*']);

    $this->getJson("/api/v1/workspace/teams/{$team->id}/candidates?q=remote")->assertOk()->assertJsonPath('data', []);
    $this->postJson("/api/v1/workspace/teams/{$team->id}/members", ['member_id' => $outsider->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('member_id');
    $this->getJson('/api/v1/workspace/team')->assertOk()->assertJsonCount(1, 'teams');
});
