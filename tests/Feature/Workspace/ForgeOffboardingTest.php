<?php

use App\Models\GitConnection;
use App\Models\GitRepository;
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

/**
 * Links {$owner}/{$name} to a project of the organization whose forge
 * organization $owner is.
 */
function forgeRepository(string $owner, string $name, int $forgeId): GitRepository
{
    $connection = GitConnection::query()->where('owner', $owner)->sole();
    $host = (string) parse_url($connection->base_url, PHP_URL_HOST);

    return GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'project_id' => Project::factory()->create(['organization_id' => $connection->organization_id])->id,
        'forge_id' => $forgeId,
        'owner' => $owner,
        'name' => $name,
        'full_name' => "{$owner}/{$name}",
        'html_url' => "https://{$host}/{$owner}/{$name}",
        'clone_url' => "https://{$host}/{$owner}/{$name}.git",
        'ssh_url' => "git@{$host}:{$owner}/{$name}.git",
        'default_branch' => 'main',
    ]);
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

it('logs each successful removal from a forge organization at warning, without the token', function () {
    Log::spy();
    Http::fake([
        'git.example.test/*' => Http::response('', 204),
        'git.other.test/*' => Http::response('', 500),
    ]);
    $sdoc = forgeOffice('sdoc');
    forgeOffice('opmcm', 'git.other.test');
    [$user] = forgePerson($sdoc);

    app(RemoveFromForgeService::class)->execute($user);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Removed someone from a forge organization.'
        && $context === ['owner' => 'sdoc', 'login' => 'ram.k'])->once();
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []) => $message === 'Removed someone from a forge organization.' && $context['owner'] === 'opmcm');
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
    // Renamed rather than dropped: other tables point at it, and Postgres refuses the drop.
    Schema::rename('git_connections', 'git_connections_gone');

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

// Repository collaborators

it('deactivating someone removes them as a collaborator from every linked repository', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    forgeOffice('opmcm', 'git.other.test');
    forgeRepository('sdoc', 'app', 501);
    forgeRepository('sdoc', 'web', 502);
    forgeRepository('opmcm', 'portal', 601);
    [, $member] = forgePerson($sdoc);

    $this->actingAs(forgeAdmin($sdoc))->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Member updated. ram.k was removed from the forge.']);

    foreach (['git.example.test/api/v1/repos/sdoc/app', 'git.example.test/api/v1/repos/sdoc/web', 'git.other.test/api/v1/repos/opmcm/portal'] as $repository) {
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === "https://{$repository}/collaborators/ram.k");
    }
    Http::assertSent(fn (Request $r) => $r->url() === 'https://git.other.test/api/v1/repos/opmcm/portal/collaborators/ram.k'
        && $r->header('Authorization') === ['token token-opmcm']);
    expect(forgeDeletes())->toBe(5);
});

it('reports where a person was taken off, organizations first', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    forgeRepository('sdoc', 'app', 501);
    [$user] = forgePerson($sdoc);

    $result = app(RemoveFromForgeService::class)->execute($user);

    expect($result->data)->toBe(['removed' => ['sdoc', 'sdoc/app'], 'failed' => []])
        ->and($result->meta)->toBe(['complete' => true]);
});

it('a failed collaborator removal makes the result incomplete', function () {
    Http::fake([
        'git.example.test/api/v1/orgs/*' => Http::response('', 204),
        'git.example.test/api/v1/repos/sdoc/app/*' => Http::response(['message' => 'boom'], 500),
        'git.example.test/api/v1/repos/sdoc/web/*' => Http::response('', 204),
    ]);
    $sdoc = forgeOffice('sdoc');
    forgeRepository('sdoc', 'app', 501);
    forgeRepository('sdoc', 'web', 502);
    [$user, $member] = forgePerson($sdoc);

    $result = app(RemoveFromForgeService::class)->execute($user);

    expect($result->success)->toBeTrue()
        ->and($result->meta)->toBe(['complete' => false])
        ->and($result->data)->toBe(['removed' => ['sdoc', 'sdoc/web'], 'failed' => ['sdoc/app']])
        ->and($result->message)->toBe("Couldn't remove ram.k from the forge; ask a super-admin to suspend them there today.");

    $this->actingAs(forgeAdmin($sdoc))->patch("/workspace/members/{$member->id}", ['is_active' => false])
        ->assertSessionHas('workspace_flash', ['success' => false, 'message' => "Member updated. Couldn't remove ram.k from the forge; ask a super-admin to suspend them there today."]);
    expect($member->refresh()->is_active)->toBeFalse();
});

it('counts a repository the forge no longer has, or a login it has no user for, as removed', function (int $status) {
    Http::fake([
        'git.example.test/api/v1/orgs/*' => Http::response('', 404),
        'git.example.test/api/v1/repos/*' => Http::response(['message' => 'gone'], $status),
    ]);
    $sdoc = forgeOffice('sdoc');
    forgeRepository('sdoc', 'app', 501);
    [$user] = forgePerson($sdoc);

    $result = app(RemoveFromForgeService::class)->execute($user);

    expect($result->data)->toBe(['removed' => ['sdoc', 'sdoc/app'], 'failed' => []])
        ->and($result->meta)->toBe(['complete' => true]);
})->with(['repository gone' => 404, 'no such forge user' => 422]);

it('logs collaborator removals like organization removals, without the token', function () {
    Log::spy();
    Http::fake([
        'git.example.test/api/v1/orgs/*' => Http::response('', 204),
        'git.example.test/api/v1/repos/sdoc/app/*' => Http::response('', 204),
        'git.example.test/api/v1/repos/sdoc/web/*' => Http::response(['message' => 'boom'], 500),
        'git.example.test/api/v1/repos/sdoc/api/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect token-sdoc'),
    ]);
    $sdoc = forgeOffice('sdoc');
    forgeRepository('sdoc', 'app', 501);
    forgeRepository('sdoc', 'web', 502);
    forgeRepository('sdoc', 'api', 503);
    [$user] = forgePerson($sdoc);

    $result = app(RemoveFromForgeService::class)->execute($user);

    expect($result->data['failed'])->toBe(['sdoc/web', 'sdoc/api']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === "Took someone off a forge repository's collaborators."
        && $context === ['owner' => 'sdoc', 'repository' => 'app', 'login' => 'ram.k'])->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Forge collaborator removal failed.'
        && $context === ['owner' => 'sdoc', 'repository' => 'web', 'status' => 500])->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Forge collaborator removal failed.'
        && $context['repository'] === 'api'
        && $context['status'] === 0
        && $context['url'] === 'https://git.example.test'
        && str_contains($context['cause'], 'cURL error 7')
        && str_contains($context['cause'], '[token]')
        && ! str_contains((string) json_encode($context), 'token-sdoc'))->once();
});

it('keeps going and reports without the token when a connection is unusable for its repositories', function () {
    Exceptions::fake();
    Http::fake(['git.other.test/*' => Http::response('', 204)]);
    $sdoc = forgeOffice('sdoc');
    forgeOffice('opmcm', 'git.other.test');
    forgeRepository('sdoc', 'app', 501);
    forgeRepository('opmcm', 'portal', 601);
    DB::table('git_connections')->where('owner', 'sdoc')->update(['token' => 'garbage-not-encrypted']);
    [$user] = forgePerson($sdoc);

    $result = app(RemoveFromForgeService::class)->execute($user);

    expect($result->data)->toBe(['removed' => ['opmcm', 'opmcm/portal'], 'failed' => ['sdoc', 'sdoc/app']])
        ->and($result->meta)->toBe(['complete' => false]);
    Exceptions::assertReported(ForgejoException::class);
    foreach (Exceptions::reported() ?? [] as $e) {
        for ($c = $e; $c !== null; $c = $c->getPrevious()) {
            expect($c->getMessage())->not->toContain('garbage-not-encrypted')->not->toContain('token-');
        }
    }
});

it('makes no collaborator call for a member without a forge account', function () {
    Http::fake();
    $sdoc = forgeOffice('sdoc');
    forgeRepository('sdoc', 'app', 501);
    [$user] = forgePerson($sdoc, null);

    app(RemoveFromForgeService::class)->execute($user);

    Http::assertNothingSent();
});
