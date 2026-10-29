<?php

use App\Models\GitConnection;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Git\ForgejoException;
use App\Services\Workspace\RemoveFromForgeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

function forgeOffice(string $slug, string $host = 'git.example.test'): Organization
{
    $organization = Organization::factory()->create(['name' => strtoupper($slug), 'slug' => $slug]);
    GitConnection::query()->create([
        'organization_id' => $organization->id,
        'base_url' => "https://{$host}",
        'owner' => $slug,
        'token' => "token-{$slug}",
    ]);

    return $organization;
}

/** @return array{0: User, 1: Member} */
function forgePerson(Organization $organization, ?string $username = 'ram.k'): array
{
    $user = User::factory()->create(['git_username' => $username]);
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id]);

    return [$user, $member];
}

function forgeAdmin(Organization $organization): User
{
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => true]);

    return $user;
}

function forgeDeletes(): int
{
    return Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->count();
}

it('removes a deactivated member from the forge with the connection token', function () {
    Http::fake(['git.example.test/*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc);

    $this->actingAs(forgeAdmin($sdoc))->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash.message', 'Member updated. ram.k was removed from the forge.');

    expect($member->refresh()->is_active)->toBeFalse();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && $r->url() === 'https://git.example.test/api/v1/orgs/sdoc/members/ram.k'
        && $r->header('Authorization') === ['token token-sdoc']);
    expect(forgeDeletes())->toBe(1);
});

it('does the same through the API', function () {
    Http::fake(['git.example.test/*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc);

    Sanctum::actingAs(forgeAdmin($sdoc));
    $this->patchJson("/api/v1/workspace/members/{$member->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('message', 'Member updated. ram.k was removed from the forge.');
});

it('counts a forge 404 as removed', function () {
    Http::fake(['git.example.test/*' => Http::response('', 404)]);
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc);

    $this->actingAs(forgeAdmin($sdoc))->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash.message', 'Member updated. ram.k was removed from the forge.');
});

it('still deactivates when the forge is down or fails', function (Closure $fake) {
    $fake();
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc);
    $failure = "Member updated. Couldn't remove ram.k from the forge; ask a super-admin to suspend them there today.";

    $this->actingAs(forgeAdmin($sdoc))->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash.message', $failure);

    expect($member->refresh()->is_active)->toBeFalse();
})->with([
    'connection error' => fn () => fn () => Http::fake(fn () => throw new ConnectionException('cURL error 7 token-sdoc')),
    'server error' => fn () => fn () => Http::fake(['git.example.test/*' => Http::response('boom', 500)]),
]);

it('removes a person from every connected organization and survives one failing', function () {
    Http::fake([
        'git.example.test/*' => Http::response('', 204),
        'git.other.test/*' => Http::response('', 500),
    ]);
    $sdoc = forgeOffice('sdoc');
    forgeOffice('opmcm', 'git.other.test');
    [$user, $member] = forgePerson($sdoc);
    $team = Project::factory()->create(['organization_id' => $sdoc->id])->ensureTeam();
    $team->update(['is_cross_organization' => true]);
    $team->members()->attach($member);

    $this->actingAs(forgeAdmin($sdoc))->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash.message', "Member updated. Couldn't remove ram.k from the forge; ask a super-admin to suspend them there today.");

    Http::assertSent(fn (Request $r) => $r->url() === 'https://git.example.test/api/v1/orgs/sdoc/members/ram.k');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://git.other.test/api/v1/orgs/opmcm/members/ram.k');

    $result = app(RemoveFromForgeService::class)->execute($user);
    expect($result->success)->toBeTrue()
        ->and($result->data)->toBe(['removed' => ['sdoc'], 'failed' => ['opmcm']]);
});

it('removes a person from both organizations when both answer', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    forgeOffice('opmcm', 'git.other.test');
    [$user] = forgePerson($sdoc);

    $result = app(RemoveFromForgeService::class)->execute($user);

    expect($result->message)->toBe('ram.k was removed from the forge.')
        ->and($result->data)->toBe(['removed' => ['sdoc', 'opmcm'], 'failed' => []]);
});

it('makes no call for a member without a forge account', function () {
    Http::fake();
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc, null);

    $this->actingAs(forgeAdmin($sdoc))->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash.message', 'Member updated.');

    Http::assertNothingSent();
    expect(app(RemoveFromForgeService::class)->execute(null)->message)->toBe('');
});

it('makes no call when the update leaves the member active', function () {
    Http::fake();
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc);
    $admin = forgeAdmin($sdoc);

    $this->actingAs($admin)->patch("/workspace/members/{$member->id}", ['title' => 'Away'])
        ->assertSessionHas('workspace_flash.message', 'Member updated.');
    $this->actingAs($admin)->patch("/workspace/members/{$member->id}", ['is_active' => true])
        ->assertSessionHas('workspace_flash.message', 'Member updated.');

    Http::assertNothingSent();
});

it('removes a deleted member from the forge, web and API', function () {
    Http::fake(['git.example.test/*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    [, $web] = forgePerson($sdoc, 'ram.k');
    [, $api] = forgePerson($sdoc, 'sita');
    $admin = forgeAdmin($sdoc);

    $this->actingAs($admin)->delete("/workspace/members/{$web->id}")
        ->assertSessionHas('workspace_flash.message', 'Member and their login removed. ram.k was removed from the forge.');

    Sanctum::actingAs($admin);
    $this->deleteJson("/api/v1/workspace/members/{$api->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Member and their login removed. sita was removed from the forge.');
});

it('treats a super-admin with no organization like anyone else', function () {
    Http::fake(['git.example.test/*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc);
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    $this->actingAs($boss)->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash.message', 'Member updated. ram.k was removed from the forge.');
});

it('never leaks the token when the forge connection fails', function () {
    Exceptions::fake();
    Http::fake(fn () => throw new ConnectionException('cURL error 7 token-sdoc'));
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc, 'ram.k');
    [, $other] = forgePerson($sdoc, 'sita');
    $admin = forgeAdmin($sdoc);

    $flash = $this->actingAs($admin)->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->baseResponse->getSession()->get('workspace_flash.message');
    Sanctum::actingAs($admin);
    $json = $this->patchJson("/api/v1/workspace/members/{$other->id}", ['is_active' => false])->assertOk()->json('message');

    expect($flash)->toContain("Couldn't remove")->not->toContain('token-sdoc')
        ->and($json)->toContain("Couldn't remove")->not->toContain('token-sdoc');
    Exceptions::assertNothingReported();
});

it('keeps going and reports without the token when a connection is unusable', function () {
    Exceptions::fake();
    Http::fake(['git.other.test/*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    forgeOffice('opmcm', 'git.other.test');
    DB::table('git_connections')->where('owner', 'sdoc')->update(['token' => 'garbage-not-encrypted']);
    [, $member] = forgePerson($sdoc);

    $this->actingAs(forgeAdmin($sdoc))->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash.message', "Member updated. Couldn't remove ram.k from the forge; ask a super-admin to suspend them there today.");

    Http::assertSent(fn (Request $r) => $r->url() === 'https://git.other.test/api/v1/orgs/opmcm/members/ram.k');
    Exceptions::assertReported(ForgejoException::class);
    foreach (Exceptions::reported() ?? [] as $e) {
        for ($c = $e; $c !== null; $c = $c->getPrevious()) {
            expect($c->getMessage())->not->toContain('garbage-not-encrypted')->not->toContain('token-');
        }
    }
});

it('encodes the username in the path', function () {
    Http::fake(['git.example.test/*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    [$user] = forgePerson($sdoc);
    $user->forceFill(['git_username' => 'ram/k l'])->save();

    app(RemoveFromForgeService::class)->execute($user);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://git.example.test/api/v1/orgs/sdoc/members/ram%2Fk%20l');
});

it('flashes success only when every forge removal worked, on update and delete', function (int $status, bool $success) {
    Http::fake(['git.example.test/*' => Http::response('', $status)]);
    $sdoc = forgeOffice('sdoc');
    [, $member] = forgePerson($sdoc, 'ram.k');
    [, $other] = forgePerson($sdoc, 'sita');
    $admin = forgeAdmin($sdoc);

    $this->actingAs($admin)->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash.success', $success);
    $this->actingAs($admin)->delete("/workspace/members/{$other->id}")
        ->assertSessionHas('workspace_flash.success', $success);
})->with([
    'working forge' => [204, true],
    'failing forge' => [500, false],
]);

it('reports the removal as incomplete when the connections cannot be loaded', function () {
    Exceptions::fake();
    $sdoc = forgeOffice('sdoc');
    [$user] = forgePerson($sdoc);
    Schema::drop('git_connections');

    $result = app(RemoveFromForgeService::class)->execute($user);

    expect($result->meta)->toBe(['complete' => false])
        ->and($result->data)->toBe(['removed' => [], 'failed' => []]);
});

it('reports a clean removal as complete', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    [$user] = forgePerson($sdoc);

    expect(app(RemoveFromForgeService::class)->execute($user)->meta)->toBe(['complete' => true])
        ->and(app(RemoveFromForgeService::class)->execute(null)->meta)->toBe(['complete' => true]);
});

it('logs why an unreachable forge was unreachable on removal, without the token', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect token-sdoc'));
    $sdoc = forgeOffice('sdoc');
    [$user] = forgePerson($sdoc);

    app(RemoveFromForgeService::class)->execute($user);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        return $context['owner'] === 'sdoc'
            && $context['url'] === 'https://git.example.test'
            && str_contains($context['cause'], 'cURL error 7')
            && str_contains($context['cause'], '[token]')
            && ! str_contains(json_encode($context), 'token-sdoc');
    })->once();
});
