<?php

use App\Models\GitConnection;
use App\Models\Member;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Git\GitAccess;
use App\Support\Git\GitUsernames;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;

/**
 * A throwaway RSA pair for the whole run: CI has no keys in storage/, so the
 * tests hand Passport key contents through config, as production does
 * through PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY.
 *
 * @return array{private: string, public: string}
 */
function oidcKeyPair(): array
{
    static $pair = null;

    if ($pair === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $pair = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    return $pair;
}

beforeEach(function () {
    config([
        'project-management.super_admins' => ['boss@example.com'],
        'passport.private_key' => oidcKeyPair()['private'],
        'passport.public_key' => oidcKeyPair()['public'],
    ]);
});

function oidcRedirect(): string
{
    return 'https://git.example.test/user/oauth2/sdoc-tasks/callback';
}

function oidcOrganization(string $slug, bool $connected = true): Organization
{
    $organization = Organization::factory()->create(['name' => strtoupper($slug), 'slug' => $slug]);

    if ($connected) {
        GitConnection::query()->create([
            'organization_id' => $organization->id,
            'base_url' => 'https://git.example.test',
            'owner' => $slug,
            'token' => 'bot-token-for-'.$slug,
        ]);
    }

    return $organization;
}

/** @param array<string, mixed> $member */
function oidcPerson(Organization $organization, array $member = [], ?string $email = null): User
{
    $user = User::factory()->create($email === null ? [] : ['email' => $email]);
    Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id] + $member);

    return $user;
}

function oidcClient(): OAuthClient
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Forgejo', [oidcRedirect()]);
    expect($client)->toBeInstanceOf(OAuthClient::class);

    return $client;
}

function oidcAuthorize(User $user, OAuthClient $client, ?string $redirectUri = null, string $scope = 'openid profile email git'): TestResponse
{
    return test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirectUri ?? oidcRedirect(),
        'response_type' => 'code',
        'scope' => $scope,
        'state' => 'xyz',
    ]));
}

/**
 * The whole authorization-code flow as Forgejo runs it. Returns the id_token
 * payload once its signature checks against the published key.
 * `$betweenSteps` runs after the code is issued and before it is exchanged.
 *
 * @return array<string, mixed>
 */
function oidcSignIn(User $user, string $scope = 'openid profile email git', ?Closure $betweenSteps = null): array
{
    $client = oidcClient();

    $location = (string) oidcAuthorize($user, $client, scope: $scope)->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith(oidcRedirect().'?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['state'] ?? null)->toBe('xyz')
        ->and($query['code'] ?? '')->not->toBe('');

    if ($betweenSteps !== null) {
        $betweenSteps();
    }

    $tokens = test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => oidcRedirect(),
        'code' => $query['code'],
    ])->assertOk();

    // The tokens only carry the sign-in: an hour, and a day to refresh.
    expect($tokens->json('expires_in'))->toBeLessThanOrEqual(3600)
        ->and(CarbonImmutable::parse((string) DB::table('oauth_refresh_tokens')->max('expires_at'))->lessThanOrEqualTo(now()->addDay()->addMinute()))->toBeTrue();

    $idToken = (string) $tokens->json('id_token');
    $parsed = (new Parser(new JoseEncoder))->parse($idToken);
    expect((new Validator)->validate($parsed, new SignedWith(new Sha256, InMemory::plainText(oidcKeyPair()['public']))))->toBeTrue();

    $payload = json_decode((string) base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/')), true);
    expect($payload['aud'] ?? null)->toContain($client->getKey());

    return $payload;
}

it('publishes discovery with the app URL as issuer, and the signing key', function () {
    $discovery = $this->getJson('/.well-known/openid-configuration')->assertOk();

    expect($discovery->json('issuer'))->toBe(config('app.url'))
        ->and($discovery->json('authorization_endpoint'))->toEndWith('/oauth/authorize')
        ->and($discovery->json('token_endpoint'))->toEndWith('/oauth/token')
        ->and($discovery->json('jwks_uri'))->toEndWith('/oauth/jwks')
        ->and($discovery->json('scopes_supported'))->toContain('openid', 'profile', 'email', 'git')
        ->and($discovery->json())->not->toHaveKey('userinfo_endpoint');

    $modulus = openssl_pkey_get_details(openssl_pkey_get_public(oidcKeyPair()['public']))['rsa']['n'];
    expect($this->getJson('/oauth/jwks')->assertOk()->json('keys.0.n'))
        ->toBe(rtrim(strtr(base64_encode($modulus), '+/', '-_'), '='));
});

it('signs an active member of a git office straight in, with every claim in the id_token', function () {
    $sdoc = oidcOrganization('sdoc');
    $user = oidcPerson($sdoc, email: 'Ram.Sharma+git@x.np');

    $payload = oidcSignIn($user);

    expect($payload['iss'])->toBe(config('app.url'))
        ->and($payload['sub'])->toBe((string) $user->id)
        ->and($payload['email'])->toBe('Ram.Sharma+git@x.np')
        ->and($payload['email_verified'])->toBeTrue()
        ->and($payload['name'])->toBe($user->name)
        ->and($payload['preferred_username'])->toBe('ram.sharma-git')
        ->and($payload['git_orgs'])->toBe(['sdoc'])
        ->and($payload['groups'])->toBe([])
        ->and(array_keys($payload))->toEqualCanonicalizing([
            'iss', 'aud', 'iat', 'exp', 'sub',
            'email', 'email_verified', 'name', 'preferred_username', 'git_orgs', 'groups',
        ]);
    expect($user->fresh()?->git_username)->toBe('ram.sharma-git');
});

it("refuses a member whose office hasn't switched git on", function () {
    oidcOrganization('sdoc');
    $user = oidcPerson(oidcOrganization('opmcm', connected: false));

    oidcAuthorize($user, oidcClient())
        ->assertForbidden()
        ->assertSee("You don't have access to git. Ask your organizational admin.");

    expect(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('refuses a deactivated member of a git office, even with a valid session', function () {
    $user = oidcPerson(oidcOrganization('sdoc'), ['is_active' => false]);

    expect(GitAccess::organizationsFor($user))->toBeEmpty();

    // Signed out before the git check.
    oidcAuthorize($user, oidcClient())->assertRedirect('/workspace/login');

    expect(DB::table('oauth_auth_codes')->count())->toBe(0);
    $this->assertGuest();
});

it('refuses a login with no member at all', function () {
    oidcOrganization('sdoc');

    oidcAuthorize(User::factory()->create(), oidcClient())->assertForbidden();
});

it("lets an outsider on a git office project's cross-organization team in, for that office only", function () {
    $sdoc = oidcOrganization('sdoc');
    $outsider = oidcPerson(oidcOrganization('opmcm', connected: false));
    $project = Project::factory()->create(['organization_id' => $sdoc->id]);
    $team = $project->ensureTeam();
    $team->update(['is_cross_organization' => true]);
    $team->members()->attach(Member::query()->where('user_id', $outsider->id)->value('id'), ['role' => 'member']);

    expect(oidcSignIn($outsider)['git_orgs'])->toBe(['sdoc']);
});

it('gives a member both their own git office and the git office of a cross-organization team they are on', function () {
    $sdoc = oidcOrganization('sdoc');
    $user = oidcPerson(oidcOrganization('mofa'));
    $team = Project::factory()->create(['organization_id' => $sdoc->id])->ensureTeam();
    $team->update(['is_cross_organization' => true]);
    $team->members()->attach(Member::query()->where('user_id', $user->id)->value('id'), ['role' => 'member']);

    expect(oidcSignIn($user)['git_orgs'])->toEqualCanonicalizing(['sdoc', 'mofa']);
});

it('refuses a member who was deleted, even with a valid session', function () {
    $user = oidcPerson(oidcOrganization('sdoc'));
    Member::query()->where('user_id', $user->id)->firstOrFail()->delete();

    oidcAuthorize($user, oidcClient())
        ->assertForbidden()
        ->assertSee("You don't have access to git. Ask your organizational admin.");
});

it('leaves git_orgs out of the id_token of a member deactivated after the code was issued', function () {
    $user = oidcPerson(oidcOrganization('sdoc'));

    $payload = oidcSignIn($user, betweenSteps: fn () => Member::query()->where('user_id', $user->id)->update(['is_active' => false]));

    expect($payload)->not->toHaveKey('git_orgs')
        ->and($payload['sub'])->toBe((string) $user->id);
});

it('puts git_orgs and groups in the id_token only for the git scope', function () {
    oidcOrganization('sdoc');
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    $payload = oidcSignIn($boss, 'openid profile email');

    expect($payload)->not->toHaveKey('git_orgs')
        ->and($payload)->not->toHaveKey('groups')
        ->and($payload)->toHaveKeys(['sub', 'email', 'preferred_username']);
});

it('refuses a deactivated outsider on a git office project team', function () {
    $sdoc = oidcOrganization('sdoc');
    $outsider = oidcPerson(oidcOrganization('opmcm', connected: false), ['is_active' => false]);
    $project = Project::factory()->create(['organization_id' => $sdoc->id]);
    $team = $project->ensureTeam();
    $team->update(['is_cross_organization' => true]);
    $team->members()->attach(Member::query()->where('user_id', $outsider->id)->value('id'), ['role' => 'member']);

    expect(GitAccess::organizationsFor($outsider))->toBeEmpty();
    oidcAuthorize($outsider, oidcClient())->assertRedirect('/workspace/login');
});

it('refuses a member whose only project team is in an office without git', function () {
    oidcOrganization('sdoc');
    $opmcm = oidcOrganization('opmcm', connected: false);
    $user = oidcPerson($opmcm);
    Project::factory()->create(['organization_id' => $opmcm->id])->ensureTeam()
        ->members()->attach(Member::query()->where('user_id', $user->id)->value('id'), ['role' => 'leader']);

    oidcAuthorize($user, oidcClient())->assertForbidden();
});

it('lets a super-admin in as forge admin, with every git office', function () {
    oidcOrganization('sdoc');
    oidcOrganization('mofa');
    oidcOrganization('opmcm', connected: false);
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    $payload = oidcSignIn($boss);

    expect($payload['groups'])->toBe(['forge-admins'])
        ->and($payload['git_orgs'])->toEqualCanonicalizing(['sdoc', 'mofa']);
});

it('issues no code for a redirect URI the client never registered', function () {
    $user = oidcPerson(oidcOrganization('sdoc'));

    $response = oidcAuthorize($user, oidcClient(), 'https://evil.example.test/callback');

    expect((string) $response->headers->get('Location'))->not->toContain('evil.example.test')
        ->and($response->isRedirect())->toBeFalse()
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('has no consent screen to show, even when asked for one', function () {
    $user = oidcPerson(oidcOrganization('sdoc'));
    $client = oidcClient();

    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => oidcRedirect(),
        'response_type' => 'code',
        'scope' => 'openid',
        'prompt' => 'consent',
    ]))->assertForbidden();

    expect(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('sends a guest to the login page first', function () {
    oidcOrganization('sdoc');
    $client = oidcClient();

    $this->get('/oauth/authorize?client_id='.$client->getKey().'&response_type=code')
        ->assertRedirect('/workspace/login');
});

it('leaves the SPA with a full page visit when signing in on the way to the forge', function () {
    $user = oidcPerson(oidcOrganization('sdoc'));
    $user->forceFill(['password' => 'password'])->save();
    $authorize = url('/oauth/authorize?client_id=abc&response_type=code');

    $this->withSession(['url.intended' => $authorize])
        ->withHeader('X-Inertia', 'true')
        ->post('/workspace/login', ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $authorize);
});

it('runs the token endpoint without a session or CSRF, and throttled', function () {
    $route = Route::getRoutes()->getByName('passport.token');
    expect($route)->not->toBeNull();

    // Nothing from the web group (session, cookies, CSRF): the forge calls it
    // server to server, and a session per call would pile up in Redis.
    expect($route->methods())->toContain('POST')
        ->and(app('router')->gatherRouteMiddleware($route))->toBe([ThrottleRequests::class.':60,1']);
});

it('gives each person a git username once, from their email, never reused', function () {
    $ram = User::factory()->create(['email' => 'Ram.Sharma+git@x.np']);
    $namesake = User::factory()->create(['email' => 'ram.sharma+git@y.np']);

    expect(GitUsernames::for($ram))->toBe('ram.sharma-git')
        ->and(GitUsernames::for($namesake))->toBe('ram.sharma-git-2');

    $ram->update(['email' => 'someone.else@x.np']);

    expect(GitUsernames::for($ram->fresh() ?? $ram))->toBe('ram.sharma-git')
        ->and($ram->fresh()?->git_username)->toBe('ram.sharma-git');
});

it('keeps git usernames to the forge alphabet and 40 characters', function () {
    $odd = User::factory()->create(['email' => '._Sita Devi!_.@x.np']);
    $long = User::factory()->create(['email' => str_repeat('a', 50).'@x.np']);
    $longTwin = User::factory()->create(['email' => str_repeat('a', 50).'@y.np']);

    expect(GitUsernames::for($odd))->toBe('sita-devi')
        ->and(GitUsernames::for($long))->toBe(str_repeat('a', 40))
        ->and(GitUsernames::for($longTwin))->toBe(str_repeat('a', 38).'-2');
});

it('never puts two separators in a row in a git username', function () {
    expect(GitUsernames::for(User::factory()->create(['email' => 'a+-b@x'])))->toBe('a-b')
        ->and(GitUsernames::for(User::factory()->create(['email' => 'ram__k@x'])))->toBe('ram-k');
});

it('treats the names the forge reserves as taken', function () {
    expect(GitUsernames::for(User::factory()->create(['email' => 'admin@x'])))->toBe('admin-2')
        ->and(GitUsernames::for(User::factory()->create(['email' => 'user@x'])))->toBe('user-2');
});

it('treats the endings the forge reserves as taken', function () {
    expect(GitUsernames::for(User::factory()->create(['email' => 'ram.png@x'])))->toBe('ram.png-2')
        ->and(GitUsernames::for(User::factory()->create(['email' => 'sita.keys@x'])))->toBe('sita.keys-2');
});

it('treats each connected forge organization and its bot as taken', function () {
    oidcOrganization('sdoc');
    GitConnection::query()->create([
        'organization_id' => Organization::factory()->create()->id,
        'base_url' => 'https://git.example.test',
        'owner' => 'MoHFS',
        'token' => 'bot-token-for-mohfs',
    ]);

    expect(GitUsernames::for(User::factory()->create(['email' => 'sdoc@x'])))->toBe('sdoc-2')
        ->and(GitUsernames::for(User::factory()->create(['email' => 'sdoc-bot@x'])))->toBe('sdoc-bot-2')
        ->and(GitUsernames::for(User::factory()->create(['email' => 'mohfs@x'])))->toBe('mohfs-2')
        ->and(GitUsernames::for(User::factory()->create(['email' => 'MoHFS-Bot@x'])))->toBe('mohfs-bot-2');
});

it('treats the break-glass forge admin as taken', function () {
    expect(GitUsernames::for(User::factory()->create(['email' => 'forge-admin@x'])))->toBe('forge-admin-2');
});

it('falls back to user and the id when the email has nothing usable', function () {
    $nepali = User::factory()->create(['email' => 'राम@x.np']);
    $symbols = User::factory()->create(['email' => '+++@x.np']);

    expect(GitUsernames::for($nepali))->toBe('user-'.$nepali->id)
        ->and(GitUsernames::for($symbols))->toBe('user-'.$symbols->id);
});

it('ends a git username on a letter or digit when the 40-character cut lands on separators', function () {
    $local = str_repeat('a', 39).'_-_'.str_repeat('b', 18);
    expect(strlen($local))->toBe(60);

    $name = GitUsernames::for(User::factory()->create(['email' => $local.'@x.np']));

    expect($name)->toBe(str_repeat('a', 39))
        ->and($name)->toMatch('/^[a-z0-9]([a-z0-9]|[._-](?=[a-z0-9]))*$/');
});

/**
 * Simulates people signing in at the same moment: right after GitUsernames
 * finds a name free, the given rival takes it (outside any savepoint, as
 * another connection would).
 *
 * @param  array<string, int>  $steals  name => the rival user id that takes it
 */
function oidcRivalsTake(array $steals): void
{
    DB::listen(function (QueryExecuted $query) use (&$steals): void {
        $name = $query->bindings[0] ?? null;

        if (str_starts_with($query->sql, 'select') && is_string($name) && isset($steals[$name])) {
            $rival = $steals[$name];
            unset($steals[$name]);
            DB::table('users')->where('id', $rival)->update(['git_username' => $name]);
        }
    });
}

it('picks the next name once when someone takes it at the same moment', function () {
    $ram = User::factory()->create(['email' => 'ram@x.np']);
    $rival = User::factory()->create(['email' => 'ram@y.np']);
    oidcRivalsTake(['ram' => $rival->id]);

    expect(GitUsernames::for($ram))->toBe('ram-2')
        ->and($ram->fresh()?->git_username)->toBe('ram-2')
        ->and($rival->fresh()?->git_username)->toBe('ram');
});

it('gives up after one retry when the race is lost twice', function () {
    $ram = User::factory()->create(['email' => 'ram@x.np']);
    $first = User::factory()->create(['email' => 'ram@y.np']);
    $second = User::factory()->create(['email' => 'ram@z.np']);
    oidcRivalsTake(['ram' => $first->id, 'ram-2' => $second->id]);

    expect(fn () => GitUsernames::for($ram))->toThrow(UniqueConstraintViolationException::class);
});

it('purges spent OAuth tokens daily', function () {
    $purge = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'passport:purge'));

    expect($purge)->not->toBeNull()
        ->and($purge->expression)->toBe('0 0 * * *')
        ->and(Artisan::call('passport:purge'))->toBe(0);
});

it('creates the Forgejo client once and prints its id and a fresh secret', function () {
    expect(Artisan::call('git:oidc-client', ['redirect_uri' => oidcRedirect()]))->toBe(0);
    $first = Artisan::output();

    $client = OAuthClient::query()->where('name', 'Forgejo')->sole();
    preg_match('/secret:\s*(\S+)/i', $first, $secret);
    expect($first)->toContain($client->getKey())
        ->and($client->redirect_uris)->toBe([oidcRedirect()])
        ->and($client->confidential())->toBeTrue()
        ->and($client->grant_types)->toBe(['authorization_code', 'refresh_token'])
        ->and(Hash::check($secret[1] ?? '', $client->secret))->toBeTrue();

    $moved = 'https://code.example.test/user/oauth2/sdoc-tasks/callback';
    expect(Artisan::call('git:oidc-client', ['redirect_uri' => $moved]))->toBe(0);
    preg_match('/secret:\s*(\S+)/i', Artisan::output(), $newSecret);

    $client = OAuthClient::query()->where('name', 'Forgejo')->sole();
    expect($client->redirect_uris)->toBe([$moved])
        ->and(Hash::check($newSecret[1] ?? '', $client->secret))->toBeTrue()
        ->and(Hash::check($secret[1] ?? '', $client->secret))->toBeFalse();
});

it('refuses to make a client for something that is not a URL', function () {
    expect(Artisan::call('git:oidc-client', ['redirect_uri' => 'git.example.test/callback']))->toBe(1)
        ->and(OAuthClient::query()->count())->toBe(0);
});
