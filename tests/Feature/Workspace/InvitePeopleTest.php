<?php

use App\Jobs\SendInvite;
use App\Mail\SetPasswordLink;
use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Workspace\InvitePeopleService;
use App\Services\Workspace\PasswordLinkService;
use App\Support\InvitePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;

/*
 * Inviting people (People page, "Invite people"): an organizational admin
 * pastes "Name, email" lines, previews who is ready, and sends each a link
 * to set a password. Nobody outside the inviter's organization is created,
 * linked, changed or told apart, whatever the client sends.
 */

beforeEach(function () {
    config([
        'project-management.super_admins' => ['boss@example.com', 'chief@example.com'],
        'queue.default' => 'sync',
    ]);
    Mail::fake();
    Sleep::fake();
});

function inviteOrganization(string $name = 'Ministry of Roads'): Organization
{
    return Organization::factory()->create(['name' => $name]);
}

function inviteAdminOf(Organization $organization, string $email = 'asha@example.com'): User
{
    $user = User::factory()->create(['email' => $email, 'name' => 'Asha Gurung']);
    Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => true]);

    return $user;
}

function inviteBoss(): User
{
    return User::factory()->create(['email' => 'boss@example.com', 'name' => 'The Boss']);
}

/** Someone of the organization who has a login. */
function invitePersonWithLogin(Organization $organization, string $email, array $user = [], array $member = []): User
{
    $login = User::factory()->create(['email' => $email, ...$user]);
    Member::factory()->linkedTo($login)->create(['organization_id' => $organization->id, ...$member]);

    return $login;
}

/** The statuses a preview gives, by email (or name when there is none). */
function inviteStatuses(array $rows): array
{
    $statuses = [];

    foreach ($rows as $row) {
        $statuses[$row['email'] ?? $row['name']] = $row['status'];
    }

    return $statuses;
}

/** @return list<array<string, mixed>> */
function invitePreview(User $as, array $body): array
{
    return test()->actingAs($as)
        ->postJson('/workspace/invites/preview', $body)
        ->assertOk()
        ->json('rows');
}

it('invites two new people into the admin\'s organization and mails each a link', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);

    $this->actingAs($admin)
        ->from('/workspace/team')
        ->post('/workspace/invites', ['people' => [
            ['name' => 'Deepak Karki', 'email' => 'deepak@example.com'],
            ['name' => 'Sunita Rai', 'email' => 'sunita@example.com'],
        ]])
        ->assertRedirect('/workspace/team')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Invited 2 people.']);

    foreach (['deepak@example.com' => 'Deepak Karki', 'sunita@example.com' => 'Sunita Rai'] as $email => $name) {
        $user = User::query()->where('email', $email)->sole();
        $member = Member::query()->where('user_id', $user->id)->sole();

        expect($user->name)->toBe($name)
            ->and($user->invited_at)->not->toBeNull()
            ->and($user->last_seen_at)->toBeNull()
            ->and(Hash::isHashed($user->password))->toBeTrue()
            ->and($member->organization_id)->toBe($roads->id)
            ->and($member->name)->toBe($name)
            ->and($member->email)->toBe($email)
            ->and($member->is_active)->toBeTrue()
            ->and($member->is_office_admin)->toBeFalse();

        Mail::assertSent(SetPasswordLink::class, fn (SetPasswordLink $mail): bool => $mail->hasTo($email)
            && $mail->kind === 'invite'
            && $mail->inviterName === 'Asha Gurung'
            && str_starts_with($mail->url, url('/workspace/invite/')));
    }

    Mail::assertSent(SetPasswordLink::class, 2);
    expect(DB::table('invite_tokens')->count())->toBe(2);
});

it('mails nobody inside the request: the links go out after the response', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);

    $result = app(InvitePeopleService::class)->execute($admin, $roads->id, [['name' => 'Deepak', 'email' => 'deepak@example.com']], false);

    expect($result->success)->toBeTrue()
        ->and($result->data)->toBe(['invited' => 1, 'skipped' => []])
        ->and(DB::table('invite_tokens')->count())->toBe(0);
    Mail::assertNothingSent();

    app()->terminate();

    Mail::assertSent(SetPasswordLink::class, fn (SetPasswordLink $mail): bool => $mail->hasTo('deepak@example.com') && $mail->kind === 'invite');
    expect(DB::table('invite_tokens')->count())->toBe(1);
});

it('lets someone whose invite email failed be invited again', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    breakMailTransport();

    $this->actingAs($admin)
        ->post('/workspace/invites', ['people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']]])
        ->assertSessionHas('workspace_flash.message', 'Invited 1 person.');

    expect(User::query()->where('email', 'deepak@example.com')->value('invited_at'))->not->toBeNull()
        ->and(inviteStatuses(invitePreview($admin, ['text' => 'Deepak, deepak@example.com'])))->toBe(['deepak@example.com' => 'resend']);
});

it('skips a person deactivated before their invite went out', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $mina = invitePersonWithLogin($roads, 'mina@example.com', member: ['is_active' => false]);

    (new SendInvite($mina->id, $admin->id))->handle(app(PasswordLinkService::class));
    (new SendInvite(999_999, $admin->id))->handle(app(PasswordLinkService::class));

    Mail::assertNothingSent();
    expect($mina->fresh()->invited_at)->toBeNull()
        ->and(DB::table('invite_tokens')->count())->toBe(0);
});

it('reads pasted lines in the usual shapes', function () {
    $people = InvitePlan::parse(implode("\n", [
        'Deepak Karki, deepak@example.com',
        '',
        '  Sunita Rai <sunita@example.com>  ',
        'Prakash Thapa prakash@example.com',
        "Mina Thapa\tmina@example.com",
        'ram@example.com',
        'Hari Bahadur',
        'Gita Shrestha, gita@nowhere',
        '"Rai, Kamala" <kamala@example.com>',
    ]));

    expect($people)->toBe([
        ['name' => 'Deepak Karki', 'email' => 'deepak@example.com'],
        ['name' => 'Sunita Rai', 'email' => 'sunita@example.com'],
        ['name' => 'Prakash Thapa', 'email' => 'prakash@example.com'],
        ['name' => 'Mina Thapa', 'email' => 'mina@example.com'],
        ['name' => '', 'email' => 'ram@example.com'],
        ['name' => 'Hari Bahadur', 'email' => null],
        ['name' => 'Gita Shrestha, gita@nowhere', 'email' => null],
        ['name' => 'Rai, Kamala', 'email' => 'kamala@example.com'],
    ]);
});

it('reads at most 200 people from a paste', function () {
    $lines = array_map(fn (int $i): string => "Person {$i}, person{$i}@example.com", range(1, 230));

    expect(InvitePlan::parse(implode("\n", $lines)))->toHaveCount(200);
});

it('previews a pasted line without an email as needing one, and never creates it', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);

    $rows = invitePreview($admin, ['text' => "Prakash Thapa\nram@example.com\nSita Rai, sita@example.com"]);

    expect($rows)->toBe([
        ['name' => 'Prakash Thapa', 'email' => null, 'designation_id' => null, 'status' => 'needs_email', 'member_id' => null],
        ['name' => '', 'email' => 'ram@example.com', 'designation_id' => null, 'status' => 'needs_name', 'member_id' => null],
        ['name' => 'Sita Rai', 'email' => 'sita@example.com', 'designation_id' => null, 'status' => 'ready', 'member_id' => null],
    ]);

    // A preview creates nothing.
    expect(User::query()->where('email', 'sita@example.com')->exists())->toBeFalse();

    $this->actingAs($admin)
        ->post('/workspace/invites', ['people' => [
            ['name' => 'Prakash Thapa', 'email' => null],
            ['name' => '', 'email' => 'ram@example.com'],
        ]])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'No one was invited.']);

    expect(Member::query()->where('name', 'Prakash Thapa')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'ram@example.com')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('gives an existing person without a login a login of their own, not a second person', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $officer = Designation::factory()->create(['organization_id' => $roads->id, 'name' => 'Section Officer']);
    $boss = Member::factory()->create(['organization_id' => $roads->id]);
    $team = Team::factory()->create(['organization_id' => $roads->id]);
    $mina = Member::factory()->create([
        'organization_id' => $roads->id,
        'name' => 'Mina Thapa',
        'email' => 'mina@example.com',
        'designation_id' => $officer->id,
        'reports_to_id' => $boss->id,
    ]);
    $team->members()->attach($mina->id, ['role' => 'member']);
    $other = Designation::factory()->create(['organization_id' => $roads->id, 'name' => 'Under Secretary']);

    expect(invitePreview($admin, ['text' => 'Mina T, MINA@example.com']))->toBe([
        ['name' => 'Mina T', 'email' => 'mina@example.com', 'designation_id' => $officer->id, 'status' => 'ready', 'member_id' => $mina->id],
    ]);

    $this->actingAs($admin)
        ->post('/workspace/invites', ['people' => [['name' => 'Mina T', 'email' => 'MINA@example.com', 'designation_id' => $other->id]]])
        ->assertSessionHas('workspace_flash.message', 'Invited 1 person.');

    $mina->refresh();
    $user = $mina->user;

    expect(Member::query()->where('email', 'mina@example.com')->count())->toBe(1)
        ->and($user)->not->toBeNull()
        ->and($user->email)->toBe('mina@example.com')
        ->and($user->name)->toBe('Mina Thapa')
        ->and($user->invited_at)->not->toBeNull()
        // A login changes nothing else about the person.
        ->and($mina->name)->toBe('Mina Thapa')
        ->and($mina->organization_id)->toBe($roads->id)
        ->and($mina->designation_id)->toBe($officer->id)
        ->and($mina->reports_to_id)->toBe($boss->id)
        ->and($mina->is_office_admin)->toBeFalse()
        ->and($team->members()->whereKey($mina->id)->exists())->toBeTrue();
    Mail::assertSent(SetPasswordLink::class, fn (SetPasswordLink $mail): bool => $mail->hasTo('mina@example.com'));
});

it('calls a person who has signed in already here, and sends them nothing', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    invitePersonWithLogin($roads, 'mina@example.com', ['last_seen_at' => now(), 'invited_at' => now()->subDays(3)]);
    // Added with a password before invites (no invite, never seen): also here.
    invitePersonWithLogin($roads, 'hari@example.com');

    expect(inviteStatuses(invitePreview($admin, ['text' => "Mina, mina@example.com\nHari, hari@example.com\nAsha, asha@example.com"])))
        ->toBe(['mina@example.com' => 'already_here', 'hari@example.com' => 'already_here', 'asha@example.com' => 'already_here']);

    $this->actingAs($admin)
        ->post('/workspace/invites', ['people' => [['name' => 'Mina', 'email' => 'mina@example.com'], ['name' => 'Hari', 'email' => 'hari@example.com']]]);

    Mail::assertNothingSent();
    expect(User::query()->count())->toBe(3);
});

it('answers another organization\'s people as already here, the same as its own, and changes nothing about them', function () {
    $roads = inviteOrganization();
    $health = inviteOrganization('Ministry of Health');
    $admin = inviteAdminOf($roads);
    $healthOfficer = Designation::factory()->create(['organization_id' => $health->id, 'name' => 'Health Officer']);
    $withLogin = invitePersonWithLogin($health, 'gita@health.example', ['invited_at' => now()->subDay()], ['designation_id' => $healthOfficer->id]);
    $withoutLogin = Member::factory()->create(['organization_id' => $health->id, 'name' => 'Kamala Rai', 'email' => 'kamala@health.example', 'designation_id' => $healthOfficer->id]);
    invitePersonWithLogin($roads, 'own@example.com', ['last_seen_at' => now()]);

    $response = $this->actingAs($admin)
        ->postJson('/workspace/invites/preview', ['text' => "G, GITA@health.example\nK, kamala@health.example\nO, own@example.com"])
        ->assertOk();

    // The same answer for another organization's people as for one's own,
    // and nothing of theirs (no id, name or designation) in it.
    expect($response->json('rows'))->toBe([
        ['name' => 'G', 'email' => 'gita@health.example', 'designation_id' => null, 'status' => 'already_here', 'member_id' => null],
        ['name' => 'K', 'email' => 'kamala@health.example', 'designation_id' => null, 'status' => 'already_here', 'member_id' => null],
        ['name' => 'O', 'email' => 'own@example.com', 'designation_id' => null, 'status' => 'already_here', 'member_id' => null],
    ]);
    expect($response->getContent())->not->toContain('Kamala Rai')
        ->not->toContain('Health Officer');

    $this->actingAs($admin)
        ->post('/workspace/invites', ['people' => [['name' => 'G', 'email' => 'gita@health.example'], ['name' => 'K', 'email' => 'kamala@health.example']]])
        ->assertSessionHas('workspace_flash.message', 'No one was invited.');

    Mail::assertNothingSent();
    expect($withoutLogin->fresh()->user_id)->toBeNull()
        ->and($withoutLogin->fresh()->organization_id)->toBe($health->id)
        ->and(User::query()->where('email', 'kamala@health.example')->exists())->toBeFalse()
        ->and($withLogin->fresh()->invited_at->toIso8601String())->toBe($withLogin->invited_at->toIso8601String())
        ->and(Member::query()->where('organization_id', $roads->id)->count())->toBe(2);
});

it('never invites a deactivated person', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $gone = Member::factory()->inactive()->create(['organization_id' => $roads->id, 'email' => 'gone@example.com']);
    invitePersonWithLogin($roads, 'left@example.com', ['invited_at' => now()->subDay()], ['is_active' => false]);

    expect(inviteStatuses(invitePreview($admin, ['text' => "Gone, gone@example.com\nLeft, left@example.com"])))
        ->toBe(['gone@example.com' => 'deactivated', 'left@example.com' => 'deactivated']);

    $this->actingAs($admin)
        ->post('/workspace/invites', ['people' => [['name' => 'Gone', 'email' => 'gone@example.com'], ['name' => 'Left', 'email' => 'left@example.com']]]);

    Mail::assertNothingSent();
    expect($gone->fresh()->user_id)->toBeNull()
        ->and(User::query()->where('email', 'gone@example.com')->exists())->toBeFalse();
});

it('sends a fresh link to someone invited who never signed in, and creates nothing', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $deepak = invitePersonWithLogin($roads, 'deepak@example.com', ['invited_at' => now()->subDays(8)]);
    app(PasswordLinkService::class)->sendInvite($deepak, $admin);
    $oldToken = DB::table('invite_tokens')->value('token');
    Mail::fake();

    expect(invitePreview($admin, ['text' => 'Deepak, deepak@example.com']))->toBe([
        ['name' => 'Deepak', 'email' => 'deepak@example.com', 'designation_id' => null, 'status' => 'resend', 'member_id' => Member::query()->where('user_id', $deepak->id)->value('id')],
    ]);

    $this->actingAs($admin)
        ->post('/workspace/invites', ['people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']]])
        ->assertSessionHas('workspace_flash.message', 'Invited 1 person.');

    Mail::assertSent(SetPasswordLink::class, 1);
    expect(User::query()->where('email', 'deepak@example.com')->count())->toBe(1)
        ->and(Member::query()->where('email', 'deepak@example.com')->count())->toBe(1)
        ->and(DB::table('invite_tokens')->count())->toBe(1)
        ->and(DB::table('invite_tokens')->value('token'))->not->toBe($oldToken);
});

it('adds the organization\'s people who have no login when asked', function () {
    $roads = inviteOrganization();
    $health = inviteOrganization('Ministry of Health');
    $admin = inviteAdminOf($roads);
    Member::factory()->create(['organization_id' => $roads->id, 'name' => 'Bina', 'email' => 'bina@example.com']);
    Member::factory()->create(['organization_id' => $roads->id, 'name' => 'Chandra', 'email' => 'chandra@example.com']);
    Member::factory()->create(['organization_id' => $roads->id, 'name' => 'No Email', 'email' => null]);
    Member::factory()->inactive()->create(['organization_id' => $roads->id, 'name' => 'Gone', 'email' => 'gone@example.com']);
    // Another organizational admin: not this admin's to invite.
    Member::factory()->create(['organization_id' => $roads->id, 'name' => 'Other Admin', 'email' => 'other.admin@example.com', 'is_office_admin' => true]);
    Member::factory()->create(['organization_id' => $health->id, 'name' => 'Health Person', 'email' => 'health@example.com']);

    $this->actingAs($admin)
        ->postJson('/workspace/invites/preview', ['text' => 'Chandra, chandra@example.com'])
        ->assertOk()
        ->assertJsonPath('without_login_count', 1)
        ->assertJsonCount(1, 'rows');

    $this->actingAs($admin)
        ->postJson('/workspace/invites/preview', ['text' => ''])
        ->assertOk()
        ->assertJsonPath('without_login_count', 2)
        ->assertJsonPath('rows', []);

    $this->actingAs($admin)
        ->post('/workspace/invites', ['people' => [], 'without_login' => true])
        ->assertSessionHas('workspace_flash.message', 'Invited 2 people.');

    expect(Member::query()->whereNotNull('user_id')->where('organization_id', $roads->id)->pluck('name')->sort()->values()->all())
        ->toBe(['Asha Gurung', 'Bina', 'Chandra'])
        ->and(Member::query()->where('name', 'Health Person')->value('user_id'))->toBeNull()
        ->and(Member::query()->where('name', 'Other Admin')->value('user_id'))->toBeNull();
    Mail::assertSent(SetPasswordLink::class, 2);
});

it('keeps a designation of the organization and drops another organization\'s', function () {
    $roads = inviteOrganization();
    $health = inviteOrganization('Ministry of Health');
    $admin = inviteAdminOf($roads);
    $own = Designation::factory()->create(['organization_id' => $roads->id]);
    $foreign = Designation::factory()->create(['organization_id' => $health->id]);

    expect(invitePreview($admin, ['people' => [
        ['name' => 'Deepak', 'email' => 'deepak@example.com', 'designation_id' => $own->id],
        ['name' => 'Sunita', 'email' => 'sunita@example.com', 'designation_id' => $foreign->id],
    ]]))->sequence(
        fn ($row) => $row->designation_id->toBe($own->id),
        fn ($row) => $row->designation_id->toBeNull(),
    );

    $this->actingAs($admin)->post('/workspace/invites', ['people' => [
        ['name' => 'Deepak', 'email' => 'deepak@example.com', 'designation_id' => $own->id],
        ['name' => 'Sunita', 'email' => 'sunita@example.com', 'designation_id' => $foreign->id],
    ]]);

    expect(Member::query()->where('email', 'deepak@example.com')->value('designation_id'))->toBe($own->id)
        ->and(Member::query()->where('email', 'sunita@example.com')->value('designation_id'))->toBeNull();
});

it('calls the same email twice already here the second time', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);

    expect(invitePreview($admin, ['text' => "sita@example.com\nSita, sita@example.com\nSita Rai, SITA@example.com"]))->sequence(
        fn ($row) => $row->status->toBe('needs_name'),
        fn ($row) => $row->status->toBe('ready'),
        fn ($row) => $row->status->toBe('already_here'),
    );

    $this->actingAs($admin)->post('/workspace/invites', ['people' => [
        ['name' => 'Sita', 'email' => 'sita@example.com'],
        ['name' => 'Sita Rai', 'email' => 'SITA@example.com'],
    ]]);

    expect(User::query()->whereRaw('lower(email) = ?', ['sita@example.com'])->count())->toBe(1);
    Mail::assertSent(SetPasswordLink::class, 1);
});

it('refuses a project manager who is not an organizational admin', function () {
    $roads = inviteOrganization();
    $team = Team::factory()->create(['organization_id' => $roads->id]);
    $manager = User::factory()->create();
    $team->members()->attach(Member::factory()->linkedTo($manager)->create(['organization_id' => $roads->id])->id, ['role' => 'leader']);
    $people = ['people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']]];

    $this->actingAs($manager)->postJson('/workspace/invites/preview', $people)->assertForbidden();
    $this->actingAs($manager)->post('/workspace/invites', $people)->assertForbidden();
    $this->actingAs($manager)->post('/workspace/invites', [...$people, 'organization_id' => $roads->id])->assertForbidden();

    expect(User::query()->where('email', 'deepak@example.com')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('refuses a deactivated organizational admin', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    Member::query()->where('user_id', $admin->id)->update(['is_active' => false]);

    $this->actingAs($admin)->post('/workspace/invites', ['people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']]]);

    expect(User::query()->where('email', 'deepak@example.com')->exists())->toBeFalse();
});

it('refuses an organizational admin inviting into another organization', function () {
    $roads = inviteOrganization();
    $health = inviteOrganization('Ministry of Health');
    $admin = inviteAdminOf($roads);
    Member::factory()->create(['organization_id' => $health->id, 'email' => 'kamala@health.example']);
    $people = ['organization_id' => $health->id, 'people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']], 'without_login' => true];

    $this->actingAs($admin)->postJson('/workspace/invites/preview', $people)->assertForbidden();
    $this->actingAs($admin)->post('/workspace/invites', $people)->assertForbidden();

    expect(User::query()->whereIn('email', ['deepak@example.com', 'kamala@health.example'])->exists())->toBeFalse()
        ->and(Member::query()->where('email', 'deepak@example.com')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('makes a super-admin name the organization', function () {
    $roads = inviteOrganization();
    $boss = inviteBoss();
    $people = ['people' => [['name' => 'Deepak', 'email' => 'deepak@example.com']]];

    $this->actingAs($boss)->postJson('/workspace/invites/preview', $people)->assertJsonValidationErrors('organization_id');
    $this->actingAs($boss)->post('/workspace/invites', $people)->assertSessionHasErrors('organization_id');
    expect(User::query()->where('email', 'deepak@example.com')->exists())->toBeFalse();

    $this->actingAs($boss)
        ->post('/workspace/invites', [...$people, 'organization_id' => $roads->id])
        ->assertSessionHas('workspace_flash.message', 'Invited 1 person.');

    expect(Member::query()->where('email', 'deepak@example.com')->value('organization_id'))->toBe($roads->id);
});

it('ignores forged statuses and ids: the plan is always rebuilt', function () {
    $roads = inviteOrganization();
    $health = inviteOrganization('Ministry of Health');
    $admin = inviteAdminOf($roads);
    $kamala = Member::factory()->create(['organization_id' => $health->id, 'name' => 'Kamala Rai', 'email' => 'kamala@health.example']);
    $signedIn = invitePersonWithLogin($roads, 'mina@example.com', ['last_seen_at' => now()]);
    $gone = Member::factory()->inactive()->create(['organization_id' => $roads->id, 'email' => 'gone@example.com']);

    $this->actingAs($admin)->post('/workspace/invites', ['people' => [
        ['name' => 'Kamala', 'email' => 'kamala@health.example', 'status' => 'ready', 'member_id' => $kamala->id],
        ['name' => 'Mina', 'email' => 'mina@example.com', 'status' => 'resend', 'member_id' => $kamala->id],
        ['name' => 'Gone', 'email' => 'gone@example.com', 'status' => 'ready', 'member_id' => $gone->id],
        ['name' => 'Nobody', 'email' => null, 'status' => 'ready'],
    ]])->assertSessionHas('workspace_flash.message', 'No one was invited.');

    Mail::assertNothingSent();
    expect($kamala->fresh()->user_id)->toBeNull()
        ->and($gone->fresh()->user_id)->toBeNull()
        ->and($signedIn->fresh()->invited_at)->toBeNull()
        ->and(User::query()->count())->toBe(2);
});

it('takes at most 200 people at once', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $people = array_map(fn (int $i): array => ['name' => "Person {$i}", 'email' => "person{$i}@example.com"], range(1, 201));

    $this->actingAs($admin)->post('/workspace/invites', ['people' => $people])->assertSessionHasErrors('people');
    $this->actingAs($admin)->postJson('/workspace/invites/preview', ['people' => $people])->assertJsonValidationErrors('people');

    expect(User::query()->count())->toBe(1);
    Mail::assertNothingSent();
});

it('never lets an organizational admin give a login to a super-admin\'s email', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $chief = Member::factory()->create(['organization_id' => $roads->id, 'email' => 'chief@example.com']);

    expect(inviteStatuses(invitePreview($admin, ['text' => "Chief, CHIEF@example.com\nBoss, boss@example.com"])))
        ->toBe(['chief@example.com' => 'not_allowed', 'boss@example.com' => 'not_allowed']);

    $this->actingAs($admin)->post('/workspace/invites', ['people' => [['name' => 'Chief', 'email' => 'chief@example.com'], ['name' => 'Boss', 'email' => 'boss@example.com']]]);

    expect($chief->fresh()->user_id)->toBeNull()
        ->and(User::query()->whereIn('email', ['chief@example.com', 'boss@example.com'])->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('never lets an organizational admin give a login to another organizational admin', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $other = Member::factory()->create(['organization_id' => $roads->id, 'email' => 'other@example.com', 'is_office_admin' => true]);

    expect(inviteStatuses(invitePreview($admin, ['text' => 'Other, other@example.com'])))->toBe(['other@example.com' => 'not_allowed']);

    $this->actingAs($admin)->post('/workspace/invites', ['people' => [['name' => 'Other', 'email' => 'other@example.com']]]);

    expect($other->fresh()->user_id)->toBeNull();
    Mail::assertNothingSent();

    // A super-admin may.
    $this->actingAs(inviteBoss())->post('/workspace/invites', ['organization_id' => $roads->id, 'people' => [['name' => 'Other', 'email' => 'other@example.com']]]);

    expect($other->fresh()->user_id)->not->toBeNull();
});

it('stores invited emails in lowercase and takes any case for the same person', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $mina = Member::factory()->create(['organization_id' => $roads->id, 'name' => 'Mina Thapa', 'email' => 'Mina.Thapa@Example.com']);

    expect(invitePreview($admin, ['text' => "Deepak Karki, Deepak.Karki@Example.com\nMina, mina.thapa@EXAMPLE.com\nDeepak, deepak.karki@example.com"]))->toBe([
        ['name' => 'Deepak Karki', 'email' => 'deepak.karki@example.com', 'designation_id' => null, 'status' => 'ready', 'member_id' => null],
        ['name' => 'Mina', 'email' => 'mina.thapa@example.com', 'designation_id' => null, 'status' => 'ready', 'member_id' => $mina->id],
        ['name' => 'Deepak', 'email' => 'deepak.karki@example.com', 'designation_id' => null, 'status' => 'already_here', 'member_id' => null],
    ]);

    $this->actingAs($admin)->post('/workspace/invites', ['people' => [
        ['name' => 'Deepak Karki', 'email' => 'Deepak.Karki@Example.com'],
        ['name' => 'Mina', 'email' => 'mina.thapa@EXAMPLE.com'],
    ]])->assertSessionHas('workspace_flash.message', 'Invited 2 people.');

    $deepak = Member::query()->where('name', 'Deepak Karki')->sole();
    expect($deepak->email)->toBe('deepak.karki@example.com')
        ->and($deepak->user->email)->toBe('deepak.karki@example.com')
        // The person and their new login keep one, lowercase, email.
        ->and($mina->fresh()->email)->toBe('mina.thapa@example.com')
        ->and($mina->fresh()->user->email)->toBe('mina.thapa@example.com');
    Mail::assertSent(SetPasswordLink::class, fn (SetPasswordLink $mail): bool => $mail->hasTo('deepak.karki@example.com'));
    Mail::assertSent(SetPasswordLink::class, fn (SetPasswordLink $mail): bool => $mail->hasTo('mina.thapa@example.com'));

    // So editing the person on the person form keeps their invite link.
    $this->actingAs($admin)
        ->patch("/workspace/members/{$mina->id}", ['name' => 'Mina Thapa Magar', 'email' => 'mina.thapa@example.com'])
        ->assertSessionHasNoErrors();
    expect(DB::table('invite_tokens')->where('email', 'mina.thapa@example.com')->exists())->toBeTrue();
});

it('leaves an existing mixed-case login as it is, and its fresh link still works', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    $hari = invitePersonWithLogin($roads, 'Hari.Rai@Example.com', ['invited_at' => now()->subDays(8)]);

    expect(inviteStatuses(invitePreview($admin, ['text' => 'Hari, hari.rai@example.com'])))->toBe(['hari.rai@example.com' => 'resend']);

    $this->actingAs($admin)->post('/workspace/invites', ['people' => [['name' => 'Hari', 'email' => 'hari.rai@example.com']]]);

    $sent = null;
    Mail::assertSent(SetPasswordLink::class, function (SetPasswordLink $mail) use (&$sent): bool {
        $sent = $mail;

        return $mail->hasTo('Hari.Rai@Example.com');
    });
    expect($hari->fresh()->email)->toBe('Hari.Rai@Example.com')
        ->and(User::query()->whereRaw('lower(email) = ?', ['hari.rai@example.com'])->count())->toBe(1);

    // The link names the login's own email, so it sets the password.
    auth()->logout();
    $this->post('/workspace/set-password', [
        'kind' => 'invite',
        'email' => 'Hari.Rai@Example.com',
        'token' => basename((string) parse_url($sent->url, PHP_URL_PATH)),
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertRedirect(route('workspace.my'));
    expect(Hash::check('new-password-1', $hari->fresh()->password))->toBeTrue();
});

it('refuses more than 200 people at once, the pasted and the added together', function () {
    $roads = inviteOrganization();
    $admin = inviteAdminOf($roads);
    Member::factory()->count(60)->create(['organization_id' => $roads->id]);
    $lines = fn (int $n): string => implode("\n", array_map(fn (int $i): string => "Person {$i}, person{$i}@example.com", range(1, $n)));
    $refusal = "That's 210 people, 210 to invite. Invite at most 200 at a time.";

    $this->actingAs($admin)->postJson('/workspace/invites/preview', ['text' => $lines(150)])
        ->assertOk()
        ->assertJsonCount(150, 'rows')
        ->assertJsonPath('without_login_count', 60);
    $this->actingAs($admin)->postJson('/workspace/invites/preview', ['text' => $lines(150), 'without_login' => true])
        ->assertStatus(422)
        ->assertExactJson(['message' => $refusal, 'errors' => ['people' => $refusal]]);
    $this->actingAs($admin)->postJson('/workspace/invites/preview', ['text' => $lines(140), 'without_login' => true])
        ->assertOk()
        ->assertJsonCount(200, 'rows');

    $people = array_map(fn (int $i): array => ['name' => "Person {$i}", 'email' => "person{$i}@example.com"], range(1, 150));
    $this->actingAs($admin)->from('/workspace/team')
        ->post('/workspace/invites', ['people' => $people, 'without_login' => true])
        ->assertRedirect('/workspace/team')
        ->assertSessionHasErrors(['people' => $refusal])
        ->assertSessionHas('workspace_flash.success', false);

    expect(User::query()->count())->toBe(1)
        ->and(Member::query()->whereNotNull('user_id')->count())->toBe(1);
    Mail::assertNothingSent();
});

it('shows Invite people on the People page to whoever may add people', function () {
    $page = (string) file_get_contents(resource_path('js/pages/Team/Index.svelte'));
    $dialog = (string) file_get_contents(resource_path('js/components/team/InviteDialog.svelte'));

    expect($page)->toContain('InviteDialog')
        ->and($page)->toContain('Invite people')
        ->and($dialog)->toContain('/workspace/invites/preview')
        ->and($dialog)->toContain('Links work for 7 days.');
});
