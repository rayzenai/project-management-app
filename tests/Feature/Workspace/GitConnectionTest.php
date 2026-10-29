<?php

use App\Models\GitConnection;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Support\WorkspaceAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

function gitBoss(): User
{
    return User::factory()->create(['email' => 'boss@example.com']);
}

function gitOrg(): Organization
{
    return Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
}

/** @return array{base_url: string, owner: string, token: string} */
function gitInput(): array
{
    return ['base_url' => 'https://git.example.test', 'owner' => 'sdoc', 'token' => 'secret-bot-token'];
}

function fakeForge(bool $isOwner = true): void
{
    Http::fake([
        'git.example.test/api/v1/user' => Http::response(['id' => 7, 'login' => 'sdoc-bot']),
        'git.example.test/api/v1/users/sdoc-bot/orgs/sdoc/permissions' => Http::response(['is_owner' => $isOwner]),
    ]);
}

it('lets a super-admin turn git on with a token that owns the forge organization', function () {
    fakeForge();
    $organization = gitOrg();

    $this->actingAs(gitBoss())->post("/workspace/organizations/{$organization->id}/git", gitInput())
        ->assertSessionHasNoErrors();

    $connection = GitConnection::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($connection->owner)->toBe('sdoc')
        ->and($connection->token)->toBe('secret-bot-token')
        ->and($connection->url())->toBe('https://git.example.test/sdoc');
    expect(DB::table('git_connections')->value('token'))->not->toContain('secret-bot-token');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'token secret-bot-token'));
});

it('shows the connection, never the token, in the organization payload', function () {
    fakeForge();
    $organization = gitOrg();
    $boss = gitBoss();
    $this->actingAs($boss)->post("/workspace/organizations/{$organization->id}/git", gitInput());

    Sanctum::actingAs($boss);
    $response = $this->getJson('/api/v1/workspace/organizations')->assertOk();

    expect($response->json('organizations.0.git'))->toEqual([
        'base_url' => 'https://git.example.test',
        'owner' => 'sdoc',
        'url' => 'https://git.example.test/sdoc',
    ]);
    expect($response->getContent())->not->toContain('secret-bot-token');
});

it('refuses a token whose user does not own the forge organization', function () {
    fakeForge(isOwner: false);
    $organization = gitOrg();

    $this->actingAs(gitBoss())->post("/workspace/organizations/{$organization->id}/git", gitInput())
        ->assertSessionHasErrors(['__global' => "sdoc-bot doesn't own sdoc on the forge."]);

    expect(GitConnection::query()->count())->toBe(0);
});

it('refuses a token the forge rejects', function () {
    Http::fake(['git.example.test/*' => Http::response([], 401)]);
    $organization = gitOrg();

    $this->actingAs(gitBoss())->post("/workspace/organizations/{$organization->id}/git", gitInput())
        ->assertSessionHasErrors(['__global' => 'The forge refused that token.']);

    expect(GitConnection::query()->count())->toBe(0);
});

it('says so when the forge cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('boom secret-bot-token'));
    $organization = gitOrg();
    Sanctum::actingAs(gitBoss());

    $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", gitInput())
        ->assertStatus(422)
        ->assertJsonPath('message', "Couldn't reach the forge at https://git.example.test.");

    expect(GitConnection::query()->count())->toBe(0);
});

it('logs the cause of an unreachable forge with the token masked', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host secret-bot-token'));
    $organization = gitOrg();

    $this->actingAs(gitBoss())->post("/workspace/organizations/{$organization->id}/git", gitInput())
        ->assertSessionHasErrors('__global');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['url'] === 'https://git.example.test'
        && str_contains($context['cause'], 'cURL error 6')
        && ! str_contains(json_encode($context), 'secret-bot-token'))->once();
});

it('updates the existing connection instead of adding a second', function () {
    fakeForge();
    $organization = gitOrg();
    $this->actingAs(gitBoss());

    $this->post("/workspace/organizations/{$organization->id}/git", gitInput());
    $this->post("/workspace/organizations/{$organization->id}/git", [...gitInput(), 'token' => 'new-token']);

    expect(GitConnection::query()->count())->toBe(1)
        ->and(GitConnection::query()->firstOrFail()->token)->toBe('new-token');
});

it('forbids office admins and project managers on web and API', function () {
    fakeForge();
    $organization = gitOrg();
    $admin = User::factory()->create();
    Member::factory()->create(['organization_id' => $organization->id, 'user_id' => $admin->id, 'is_office_admin' => true]);
    $manager = User::factory()->create();
    $managerMember = Member::factory()->create(['organization_id' => $organization->id, 'user_id' => $manager->id]);
    $team = Team::factory()->create(['organization_id' => $organization->id]);
    $team->members()->attach($managerMember->id, ['role' => 'leader']);
    expect(WorkspaceAccess::ledTeamIds($manager))->toContain($team->id);

    foreach ([$admin, $manager] as $user) {
        $this->actingAs($user)->post("/workspace/organizations/{$organization->id}/git", gitInput())->assertForbidden();
        $this->actingAs($user)->delete("/workspace/organizations/{$organization->id}/git")->assertForbidden();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", gitInput())->assertForbidden();
        $this->deleteJson("/api/v1/workspace/organizations/{$organization->id}/git")->assertForbidden();
    }

    expect(GitConnection::query()->count())->toBe(0);
});

it('turns git off by removing the connection', function () {
    fakeForge();
    $organization = gitOrg();
    $this->actingAs(gitBoss());
    $this->post("/workspace/organizations/{$organization->id}/git", gitInput());

    $this->delete("/workspace/organizations/{$organization->id}/git")->assertSessionHasNoErrors();

    expect(GitConnection::query()->count())->toBe(0);
});

it('has an API twin for turning git on and off', function () {
    fakeForge();
    $organization = gitOrg();
    Sanctum::actingAs(gitBoss());

    $response = $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", gitInput())->assertOk();
    expect($response->getContent())->not->toContain('secret-bot-token');
    expect(GitConnection::query()->count())->toBe(1);

    $this->deleteJson("/api/v1/workspace/organizations/{$organization->id}/git")->assertOk();
    expect(GitConnection::query()->count())->toBe(0);
});

it('validates the input', function () {
    $organization = gitOrg();
    $this->actingAs(gitBoss())->post("/workspace/organizations/{$organization->id}/git", ['base_url' => 'nope'])
        ->assertSessionHasErrors(['base_url', 'owner', 'token']);
});

it('never flashes the bot token back into old input', function () {
    $organization = gitOrg();

    $this->actingAs(gitBoss())->from('/workspace/organizations')
        ->post("/workspace/organizations/{$organization->id}/git", [...gitInput(), 'base_url' => 'nope'])
        ->assertSessionHasErrors('base_url')
        ->assertSessionHasInput('owner', 'sdoc')
        ->assertSessionMissing('_old_input.token');
});

it('refuses a plain http forge address unless the forge is on this machine', function () {
    fakeForge();
    Http::fake(['127.0.0.1:3300/api/v1/user' => Http::response(['id' => 7, 'login' => 'sdoc-bot']), '127.0.0.1:3300/api/v1/users/sdoc-bot/orgs/sdoc/permissions' => Http::response(['is_owner' => true])]);
    $organization = gitOrg();
    $message = 'Use https for the forge address. Plain http is only for 127.0.0.1, localhost or ::1.';

    $boss = gitBoss();

    $this->actingAs($boss)->post("/workspace/organizations/{$organization->id}/git", [...gitInput(), 'base_url' => 'http://git.example.test'])
        ->assertSessionHasErrors(['base_url' => $message]);
    Sanctum::actingAs($boss);
    $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", [...gitInput(), 'base_url' => 'HTTP://git.example.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base_url' => $message]);
    expect(GitConnection::query()->count())->toBe(0);

    $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", [...gitInput(), 'base_url' => 'http://127.0.0.1:3300'])
        ->assertOk();
    expect(GitConnection::query()->sole()->base_url)->toBe('http://127.0.0.1:3300');
});

it('refuses a forge organization another office is already connected to', function () {
    fakeForge();
    $sdoc = gitOrg();
    $other = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    $boss = gitBoss();
    $this->actingAs($boss);
    $this->post("/workspace/organizations/{$sdoc->id}/git", gitInput())->assertSessionHasNoErrors();

    $this->post("/workspace/organizations/{$other->id}/git", [...gitInput(), 'base_url' => 'HTTPS://Git.Example.Test/', 'owner' => 'SDOC'])
        ->assertSessionHasErrors(['owner' => 'SDOC is already connected to another office.']);
    Sanctum::actingAs($boss);
    $this->postJson("/api/v1/workspace/organizations/{$other->id}/git", gitInput())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['owner' => 'sdoc is already connected to another office.']);
    expect(GitConnection::query()->where('organization_id', $other->id)->exists())->toBeFalse();

    // The same office may connect again (a new token), and another forge may use the same name.
    $this->postJson("/api/v1/workspace/organizations/{$sdoc->id}/git", [...gitInput(), 'token' => 'new-token'])->assertOk();
    Http::fake([
        'other.example.test/api/v1/user' => Http::response(['id' => 8, 'login' => 'sdoc-bot']),
        'other.example.test/api/v1/users/sdoc-bot/orgs/sdoc/permissions' => Http::response(['is_owner' => true]),
    ]);
    $this->postJson("/api/v1/workspace/organizations/{$other->id}/git", [...gitInput(), 'base_url' => 'https://other.example.test'])->assertOk();
    expect(GitConnection::query()->count())->toBe(2);
});

it('reports an unexpected failure without the token in the message or the log', function () {
    fakeForge();
    Exceptions::fake();
    $organization = gitOrg();
    Schema::table('git_connections', fn (Blueprint $table) => $table->dropColumn('token'));
    Sanctum::actingAs(gitBoss());

    $response = $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", gitInput())->assertServerError();

    expect($response->getContent())->not->toContain('secret-bot-token')->not->toContain('eyJpdiI');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getPrevious() === null
        && ! str_contains($e->getMessage(), 'secret-bot-token')
        && ! str_contains($e->getMessage(), 'eyJpdiI'));
    Exceptions::assertReportedCount(1);
});

function enableGit(Organization $organization, array $input = []): TestResponse
{
    return test()->actingAs(User::query()->where('email', 'boss@example.com')->first() ?? gitBoss())->post("/workspace/organizations/{$organization->id}/git", [...gitInput(), ...$input]);
}

it('says the address is not a forge when /user answers 404, or 200 without a login', function (mixed $response) {
    Http::fake(['git.example.test/*' => $response]);
    $organization = gitOrg();

    enableGit($organization)
        ->assertSessionHasErrors(['__global' => "https://git.example.test doesn't look like a Forgejo forge."]);

    expect(GitConnection::query()->count())->toBe(0);
})->with([
    '404' => fn () => Http::response([], 404),
    'html' => fn () => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
    'empty object' => fn () => Http::response([]),
]);

it('asks to try again when the forge answers another failure', function (int $status) {
    Http::fake(['git.example.test/*' => Http::response([], $status)]);
    $organization = gitOrg();

    enableGit($organization)
        ->assertSessionHasErrors(['__global' => "The forge answered {$status}. Try again shortly."]);

    expect(GitConnection::query()->count())->toBe(0);
})->with([429, 400]);

it('maps 401, 403, 500 and a dropped connection to their messages', function (mixed $response, string $message) {
    Http::fake(['git.example.test/*' => $response]);
    $organization = gitOrg();

    enableGit($organization)->assertSessionHasErrors(['__global' => $message]);

    expect(GitConnection::query()->count())->toBe(0);
})->with([
    '401' => [fn () => Http::response([], 401), 'The forge refused that token.'],
    '403' => [fn () => Http::response([], 403), 'The forge refused that token.'],
    '500' => [fn () => Http::response([], 500), "Couldn't reach the forge at https://git.example.test."],
    'connection' => [fn () => fn () => throw new ConnectionException('boom'), "Couldn't reach the forge at https://git.example.test."],
]);

it('maps a permissions answer to a message', function (int $status, string $message) {
    Http::fake([
        'git.example.test/api/v1/user' => Http::response(['id' => 7, 'login' => 'sdoc-bot']),
        'git.example.test/api/v1/users/sdoc-bot/orgs/sdoc/permissions' => Http::response([], $status),
    ]);
    $organization = gitOrg();

    enableGit($organization)->assertSessionHasErrors(['__global' => $message]);

    expect(GitConnection::query()->count())->toBe(0);
})->with([
    '404' => [404, "sdoc-bot doesn't own sdoc on the forge."],
    '403' => [403, "sdoc-bot doesn't own sdoc on the forge."],
    '500' => [500, "Couldn't reach the forge at https://git.example.test."],
    '429' => [429, 'The forge answered 429. Try again shortly.'],
]);

it('keeps the old connection untouched when a re-enable fails', function () {
    Http::fake(fn ($request) => $request->hasHeader('Authorization', 'token secret-bot-token')
        ? (str_ends_with($request->url(), '/user') ? Http::response(['id' => 7, 'login' => 'sdoc-bot']) : Http::response(['is_owner' => true]))
        : Http::response([], 401));
    $organization = gitOrg();
    enableGit($organization)->assertSessionHasNoErrors();
    $before = GitConnection::query()->sole()->getAttributes();

    enableGit($organization, ['token' => 'other-token'])->assertSessionHasErrors(['__global' => 'The forge refused that token.']);

    expect(GitConnection::query()->sole()->getAttributes())->toEqual($before);
});

it('gives the same message on the API for a refused token', function () {
    Http::fake(['git.example.test/*' => Http::response([], 401)]);
    $organization = gitOrg();
    Sanctum::actingAs(gitBoss());

    $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", gitInput())
        ->assertStatus(422)
        ->assertJsonPath('message', 'The forge refused that token.');
});

it('refuses a forge address with a user name or password', function () {
    fakeForge();
    $organization = gitOrg();
    $message = "The forge address can't contain a user name or password.";

    enableGit($organization, ['base_url' => 'https://u:p@git.example.test'])
        ->assertSessionHasErrors(['base_url' => $message]);
    Sanctum::actingAs(User::query()->where('email', 'boss@example.com')->firstOrFail());
    $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", [...gitInput(), 'base_url' => 'https://u:p@git.example.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base_url' => $message]);

    expect(GitConnection::query()->count())->toBe(0);
});

it('normalizes the address and keeps the webhook when the same forge is re-enabled', function () {
    Http::fake([
        '*/api/v1/user' => Http::response(['id' => 7, 'login' => 'sdoc-bot']),
        '*/permissions' => Http::response(['is_owner' => true]),
    ]);
    $organization = gitOrg();
    enableGit($organization, ['base_url' => 'HTTPS://Git.Example.Test/'])->assertSessionHasNoErrors();
    $connection = GitConnection::query()->sole();
    expect($connection->base_url)->toBe('https://git.example.test');
    $connection->forceFill(['webhook_id' => 42, 'webhook_secret' => 'hook-secret'])->save();

    enableGit($organization, ['base_url' => 'https://git.example.test/', 'owner' => 'SDOC'])->assertSessionHasNoErrors();

    $connection = GitConnection::query()->sole();
    expect($connection->webhook_id)->toBe(42)->and($connection->webhook_secret)->toBe('hook-secret');
});

it('treats a default port as the same forge', function () {
    expect(GitConnection::normalizeBaseUrl('https://Git.Example:443/'))->toBe('https://git.example')
        ->and(GitConnection::normalizeBaseUrl('http://git.example:80'))->toBe('http://git.example')
        ->and(GitConnection::normalizeBaseUrl('https://git.example:8443'))->toBe('https://git.example:8443')
        ->and(GitConnection::normalizeBaseUrl('http://git.example:443'))->toBe('http://git.example:443');
});

it('clears the webhook when the host or the owner changes', function (string $baseUrl, string $owner) {
    Http::fake([
        '*/api/v1/user' => Http::response(['id' => 7, 'login' => 'sdoc-bot']),
        '*/permissions' => Http::response(['is_owner' => true]),
    ]);
    $organization = gitOrg();
    enableGit($organization)->assertSessionHasNoErrors();
    GitConnection::query()->sole()->forceFill(['webhook_id' => 42, 'webhook_secret' => 'hook-secret'])->save();

    enableGit($organization, ['base_url' => $baseUrl, 'owner' => $owner])->assertSessionHasNoErrors();

    $connection = GitConnection::query()->sole();
    expect($connection->webhook_id)->toBeNull()->and($connection->webhook_secret)->toBeNull();
})->with([
    'host' => ['https://other.example.test', 'sdoc'],
    'owner' => ['https://git.example.test', 'opmcm'],
]);

it('refuses a second row for the same forge organization in the database', function () {
    $first = gitOrg();
    $second = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    $row = fn (int $organizationId, string $owner) => DB::table('git_connections')->insert([
        'organization_id' => $organizationId, 'base_url' => 'https://git.example.test', 'owner' => $owner,
        'token' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row($first->id, 'sdoc');

    expect(fn () => $row($second->id, 'SDOC'))->toThrow(UniqueConstraintViolationException::class);
});

it('carries the connection exception as the previous one', function () {
    Http::fake(fn () => throw new ConnectionException('boom'));

    try {
        (new ForgejoClient('https://git.example.test', 'secret-bot-token'))->currentUser();
        $this->fail('Expected a ForgejoException.');
    } catch (ForgejoException $e) {
        expect($e->status)->toBe(0)->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('refuses a forge address with a query or fragment', function (string $baseUrl) {
    fakeForge();
    $organization = gitOrg();
    $message = "The forge address can't have a query or fragment.";

    enableGit($organization, ['base_url' => $baseUrl])->assertSessionHasErrors(['base_url' => $message]);
    Sanctum::actingAs(User::query()->where('email', 'boss@example.com')->firstOrFail());
    $this->postJson("/api/v1/workspace/organizations/{$organization->id}/git", [...gitInput(), 'base_url' => $baseUrl])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base_url' => $message]);

    expect(GitConnection::query()->count())->toBe(0);
})->with(['https://git.example.test?x=1', 'https://git.example.test/#a']);
