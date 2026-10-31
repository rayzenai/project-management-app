<?php

use App\Jobs\SyncRepositoryAccess;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\GitWebhookDelivery;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\Git\MainBranch;
use App\Services\Git\RepositoryAccess;
use App\Services\Git\RepositoryAccessFailed;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * The access sync: a linked repository's forge collaborators made to match
 * the tracker (its maintainers as admin, its effective grants), against a
 * faked Forgejo 15. Fixtures follow its API structs, as
 * ProjectRepositoryTest's do; GET collaborators lists api.User rows, the
 * permission endpoint answers api.RepoCollaboratorPermission. Stray requests
 * fail the test, so every forge call is one a stub expects.
 *
 * Project Tax Portal (SDOC) is in the program Revenue. Mira manages it,
 * Pradeep manages the program; Asha (on the team) has write, Bikash (SDOC,
 * not on the team) has read. On the forge, sdoc/tp-api's collaborators are
 * the bot, Mira, Asha, Chet (a former developer whose grant was deleted,
 * written "Chet" by the forge) and a contractor no tracker user is.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00'));
    Http::preventStrayRequests();

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->otherOrganization = Organization::factory()->create(['name' => 'Elsewhere']);
    $this->connection = GitConnection::query()->create([
        'organization_id' => $this->organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);

    [$this->managerUser, $this->manager] = accPerson($this->organization, 'mira');
    [, $this->programManager] = accPerson($this->organization, 'pradeep');
    $this->program = Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => $this->programManager->id, 'name' => 'Revenue']);

    $this->project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'program_id' => $this->program->id,
        'title' => 'Tax Portal',
        'key' => 'TP',
    ]);
    $this->team = $this->project->ensureTeam();

    [, $this->asha] = accPerson($this->organization, 'asha');
    [, $this->bikash] = accPerson($this->organization, 'bikash');
    [, $this->chet] = accPerson($this->organization, 'chet');

    $this->team->members()->attach($this->manager->id, ['role' => 'leader']);
    $this->team->members()->attach($this->asha->id, ['role' => 'member']);
    $this->team->members()->attach($this->chet->id, ['role' => 'member']);

    $this->repo = accLinked($this->project, 'tp-api', 501);
    accGrant($this->repo, $this->asha, GitRepositoryGrant::WRITE);
    accGrant($this->repo, $this->bikash, GitRepositoryGrant::READ);
});

/** @return array{0: User, 1: Member} */
function accPerson(Organization $organization, ?string $login, bool $active = true, ?string $name = null): array
{
    $user = User::factory()->create(['git_username' => $login, ...($name !== null ? ['name' => $name] : [])]);
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_active' => $active]);

    return [$user, $member];
}

function accLinked(Project $project, string $name, int $forgeId): GitRepository
{
    return GitRepository::query()->create([
        'git_connection_id' => GitConnection::query()->where('owner', 'sdoc')->sole()->id,
        'project_id' => $project->id,
        'forge_id' => $forgeId,
        'owner' => 'sdoc',
        'name' => $name,
        'full_name' => "sdoc/{$name}",
        'html_url' => "https://git.example/sdoc/{$name}",
        'clone_url' => "https://git.example/sdoc/{$name}.git",
        'ssh_url' => "git@git.example:sdoc/{$name}.git",
        'default_branch' => 'main',
        'reconciled_at' => now()->subDay(),
    ]);
}

function accGrant(GitRepository $repo, Member $member, string $permission): GitRepositoryGrant
{
    return GitRepositoryGrant::query()->create([
        'git_repository_id' => $repo->id,
        'member_id' => $member->id,
        'permission' => $permission,
    ]);
}

/**
 * A list endpoint: the rows on the first page, nothing after it.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function accPage(array $rows): Closure
{
    return function (Request $request) use ($rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? '1') === '1' ? $rows : []);
    };
}

/**
 * api.User rows on a list endpoint.
 *
 * @param  list<string>  $logins
 */
function accLogins(array $logins): Closure
{
    return accPage(array_map(fn (string $login): array => ['id' => crc32($login), 'login' => $login, 'username' => $login], $logins));
}

/**
 * PUT and DELETE of single collaborators: 204, unless $answers names a
 * status for that login.
 *
 * @param  array<string, int>  $answers  login => status
 */
function accCollaboratorWrites(array $answers = []): Closure
{
    return function (Request $request) use ($answers) {
        $login = rawurldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));
        $status = $answers[$login] ?? 204;

        return match ($status) {
            204 => Http::response('', 204),
            422 => Http::response(['message' => "user does not exist [uid: 0, name: {$login}]", 'url' => 'https://git.example/api/swagger'], 422),
            default => Http::response(['message' => 'Internal Server Error'], $status),
        };
    };
}

/**
 * sdoc/{name} on the forge, its collaborators $logins; specific stubs in
 * $stubs win over these.
 *
 * @param  list<string>  $logins
 * @param  array<string, mixed>  $stubs
 * @return array<string, mixed>
 */
function accForge(array $logins = ['sdoc-bot', 'mira', 'asha', 'Chet', 'contractor'], array $stubs = [], string $name = 'tp-api'): array
{
    $repo = "git.example/api/v1/repos/sdoc/{$name}";

    return $stubs + [
        "{$repo}/collaborators?*" => accLogins($logins),
        "{$repo}/collaborators/*" => accCollaboratorWrites(),
        // A rule that still lets people push, as a maintainer might leave it.
        "{$repo}/branch_protections/main" => Http::response(['rule_name' => 'main', 'branch_name' => 'main', ...MainBranch::RULE, 'enable_push' => true]),
        "{$repo}/branches/*" => Http::response(['name' => 'main']),
    ];
}

/**
 * The writes sent to the forge, as "PUT mira admin", "DELETE Chet" or
 * "PATCH main".
 *
 * @return list<string>
 */
function accWrites(string $name = 'tp-api'): array
{
    return Http::recorded(fn (Request $r) => $r->method() !== 'GET' && str_contains($r->url(), "/repos/sdoc/{$name}/"))
        ->map(function (array $pair): string {
            [$request] = $pair;
            $last = rawurldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));

            return match ($request->method()) {
                'PUT' => "PUT {$last} {$request['permission']}",
                default => "{$request->method()} {$last}",
            };
        })
        ->values()
        ->all();
}

/** @return list<string> */
function accSorted(array $values): array
{
    sort($values);

    return array_values($values);
}

// Who is wanted

it('makes the manager and program manager admins and keeps each grant\'s permission', function () {
    // A grant the manager still holds from before: the higher permission wins.
    accGrant($this->repo, $this->manager, GitRepositoryGrant::READ);
    Http::fake(accForge());

    expect(app(RepositoryAccess::class)->wanted($this->repo))->toEqual([
        'logins' => ['mira' => 'admin', 'pradeep' => 'admin', 'asha' => 'write', 'bikash' => 'read'],
        'waiting' => [],
    ]);

    $result = app(RepositoryAccess::class)->sync($this->repo);

    expect(accSorted(array_filter(accWrites(), fn (string $w) => str_starts_with($w, 'PUT'))))->toBe([
        'PUT asha write',
        'PUT bikash read',
        'PUT mira admin',
        'PUT pradeep admin',
    ])
        ->and(accSorted($result['granted']))->toBe(['asha', 'bikash', 'mira', 'pradeep'])
        ->and($this->repo->refresh()->access_synced_at?->equalTo(now()))->toBeTrue()
        ->and($this->repo->protects_main)->toBeTrue();
    // The forge's rule had drifted: the sync puts the tracker's back.
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/branch_protections/main'));
});

it('wants nobody for a grant that no longer counts', function () {
    // Bikash moved to another organization and is not on the team.
    $this->bikash->update(['organization_id' => $this->otherOrganization->id]);

    expect(app(RepositoryAccess::class)->wanted($this->repo)['logins'])->toEqual(['mira' => 'admin', 'pradeep' => 'admin', 'asha' => 'write']);
});

it('logs each new collaborator at warning, without the token, and not again once they are one', function () {
    Log::spy();
    Http::fake(accForge());

    app(RepositoryAccess::class)->sync($this->repo);

    // Mira and Asha were already collaborators; their PUTs change nothing to report.
    foreach (['pradeep' => 'admin', 'bikash' => 'read'] as $login => $permission) {
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Gave someone access to a forge repository.'
            && $context == ['owner' => 'sdoc', 'repository' => 'tp-api', 'login' => $login, 'permission' => $permission])->once();
    }
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []) => in_array($context['login'] ?? null, ['mira', 'asha'], true));
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []) => str_contains((string) json_encode($context), 'never-shown'));
});

// Who is removed

it('removes someone the tracker knows who no longer has access, logged without the token', function () {
    Log::spy();
    Http::fake(accForge());

    $result = app(RepositoryAccess::class)->sync($this->repo);

    expect(array_values(array_filter(accWrites(), fn (string $w) => str_starts_with($w, 'DELETE'))))->toBe(['DELETE Chet'])
        ->and($result['removed'])->toBe(['Chet']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === "Took someone off a forge repository's collaborators."
        && $context == ['owner' => 'sdoc', 'repository' => 'tp-api', 'login' => 'Chet', 'permission' => 'none'])->once();
});

it('never removes someone the tracker doesn\'t know', function () {
    Http::fake(accForge());

    app(RepositoryAccess::class)->sync($this->repo);

    expect(accWrites())->not->toContain('DELETE contractor');
});

it('never removes the bot or forge-admin, even when a user carries their name', function () {
    // Forge usernames can't take these names; data written by hand could.
    accPerson($this->organization, 'sdoc-bot');
    accPerson($this->organization, 'forge-admin');
    Http::fake(accForge(['sdoc-bot', 'forge-admin', 'mira']));

    $result = app(RepositoryAccess::class)->sync($this->repo);

    expect($result['removed'])->toBe([])
        ->and(array_filter(accWrites(), fn (string $w) => str_starts_with($w, 'DELETE')))->toBe([]);
});

it('never wants the bot or forge-admin, even when a person carries their name', function () {
    $this->repo->forceFill(['protects_main' => true])->save();
    $this->managerUser->forceFill(['git_username' => 'SDOC-bot'])->save();
    [, $forgeAdmin] = accPerson($this->organization, 'forge-admin');
    accGrant($this->repo, $forgeAdmin, GitRepositoryGrant::WRITE);
    Http::fake(accForge());

    expect(app(RepositoryAccess::class)->wanted($this->repo)['logins'])->toEqual(['pradeep' => 'admin', 'asha' => 'write', 'bikash' => 'read']);

    app(RepositoryAccess::class)->sync($this->repo);

    expect(accSorted(array_values(array_filter(accWrites(), fn (string $w) => str_starts_with($w, 'PUT')))))->toBe(['PUT asha write', 'PUT bikash read', 'PUT pradeep admin']);
    // Nobody pushes to main, the bot and forge-admin included.
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['enable_push'] === false && $r['push_whitelist_usernames'] === []);
});

it('keeps a collaborator the forge spells in another case: one PUT, no removal', function () {
    Log::spy();
    Http::fake(accForge(['sdoc-bot', 'mira', 'ASHA', 'Chet']));

    $result = app(RepositoryAccess::class)->sync($this->repo);

    expect(array_values(array_filter(accWrites(), fn (string $w) => str_contains($w, 'asha') || str_contains($w, 'ASHA'))))->toBe(['PUT asha write'])
        ->and($result['removed'])->toBe(['Chet']);
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []) => mb_strtolower((string) ($context['login'] ?? '')) === 'asha');
});

// Who is waiting

it('does not want a deactivated person, and takes them off', function () {
    $this->asha->update(['is_active' => false]);
    $this->manager->update(['is_active' => false]);
    Http::fake(accForge());

    $result = app(RepositoryAccess::class)->sync($this->repo);

    expect(accWrites())->not->toContain('PUT asha write')
        ->not->toContain('PUT mira admin')
        ->toContain('DELETE asha', 'DELETE mira')
        ->and(accSorted($result['granted']))->toBe(['bikash', 'pradeep']);
});

it('lists someone who never signed in to the forge as waiting', function () {
    [, $dina] = accPerson($this->organization, null, name: 'Dina Waiting');
    $era = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Era Unlinked']);
    accGrant($this->repo, $dina, GitRepositoryGrant::WRITE);
    accGrant($this->repo, $era, GitRepositoryGrant::READ);
    Http::fake(accForge());

    expect(app(RepositoryAccess::class)->wanted($this->repo)['waiting'])->toBe(accSorted([$dina->id, $era->id]));

    $result = app(RepositoryAccess::class)->sync($this->repo);

    expect(accSorted($result['waiting']))->toBe(['Dina Waiting', 'Era Unlinked'])
        ->and(accWrites())->toHaveCount(6); // four PUTs, Chet's removal and main's rule
});

// When the forge says no

it('marks a login the forge has no user for as waiting, and goes on', function () {
    Http::fake(accForge(stubs: ['git.example/api/v1/repos/sdoc/tp-api/collaborators/*' => accCollaboratorWrites(['bikash' => 422])]));

    $result = app(RepositoryAccess::class)->sync($this->repo);

    expect($result['waiting'])->toBe(['bikash'])
        ->and(accSorted($result['granted']))->toBe(['asha', 'mira', 'pradeep'])
        ->and($result['removed'])->toBe(['Chet'])
        ->and($this->repo->refresh()->access_synced_at)->not->toBeNull();
});

it('still removes, grants the others and rewrites main when one PUT is refused, then fails the run', function () {
    // The forge answers 500 for an account it disabled.
    $this->repo->forceFill(['protects_main' => true])->save();
    Http::fake(accForge(stubs: ['git.example/api/v1/repos/sdoc/tp-api/collaborators/*' => accCollaboratorWrites(['asha' => 500])]));

    expect(fn () => app(RepositoryAccess::class)->sync($this->repo))
        ->toThrow(fn (RepositoryAccessFailed $e) => expect([$e->status, $e->operation, $e->login, $e->getMessage()])
            ->toBe([500, 'put', 'asha', 'The forge answered 500 (put asha).']));

    expect(accWrites())->toContain('DELETE Chet', 'PUT mira admin', 'PUT pradeep admin', 'PUT bikash read', 'PATCH main')
        ->and(accWrites()[0])->toBe('DELETE Chet')
        ->and($this->repo->refresh()->access_synced_at)->toBeNull();
});

it('stops before any grant when a removal fails', function () {
    Http::fake(accForge(stubs: ['git.example/api/v1/repos/sdoc/tp-api/collaborators/*' => accCollaboratorWrites(['Chet' => 500])]));

    expect(fn () => app(RepositoryAccess::class)->sync($this->repo))
        ->toThrow(fn (RepositoryAccessFailed $e) => expect([$e->status, $e->operation, $e->login])->toBe([500, 'delete', 'Chet']));

    expect(accWrites())->toBe(['DELETE Chet']);
});

it('stops at once when the forge stops answering a PUT', function () {
    $calls = 0;
    Http::fake(accForge(stubs: ['git.example/api/v1/repos/sdoc/tp-api/collaborators/*' => function (Request $request) use (&$calls) {
        if ($request->method() !== 'PUT') {
            return Http::response('', 204);
        }

        $calls++;

        throw new ConnectionException('cURL error 28: timed out');
    }]));

    expect(fn () => app(RepositoryAccess::class)->sync($this->repo))
        ->toThrow(fn (RepositoryAccessFailed $e) => expect([$e->status, $e->operation])->toBe([0, 'put']));

    expect($calls)->toBe(1);
});

// The main rule

it('writes the tracker\'s rule onto main last, on every run the forge\'s has drifted', function () {
    // The forge's rule still lets people push: the sync puts the tracker's back.
    Http::fake(accForge());
    $rules = fn (): array => Http::recorded(fn (Request $r) => $r->method() === 'PATCH')
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();

    app(RepositoryAccess::class)->sync($this->repo);
    app(RepositoryAccess::class)->sync($this->repo);

    expect($rules())->toBe([MainBranch::RULE, MainBranch::RULE])
        ->and(accWrites()[array_key_last(accWrites())])->toBe('PATCH main')
        ->and($this->repo->refresh()->protects_main)->toBeTrue();
});

it('reads the rule and writes nothing while it is the tracker\'s, so open pull requests are not tested again', function () {
    Http::fake(accForge(stubs: [
        // Forgejo gives an empty list as null.
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => Http::response(['rule_name' => 'main', ...MainBranch::RULE, 'push_whitelist_usernames' => null]),
    ]));

    app(RepositoryAccess::class)->sync($this->repo);

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && str_contains($r->url(), '/branch_protections'));
    expect($this->repo->refresh()->protects_main)->toBeTrue();
});

it('puts back every field of the rule, the file patterns and approval lists among them', function (string $field, mixed $loosened) {
    Http::fake(accForge(stubs: [
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => Http::response(['rule_name' => 'main', ...MainBranch::RULE, $field => $loosened]),
    ]));

    app(RepositoryAccess::class)->sync($this->repo);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/branch_protections/main') && $r->data() === MainBranch::RULE);
})->with([
    // Lets any push that only touches matching files through, admins or not.
    'unprotected files' => ['unprotected_file_patterns', '**'],
    'protected files' => ['protected_file_patterns', '**'],
    'approvals list' => ['enable_approvals_whitelist', true],
    'merge list' => ['enable_merge_whitelist', true],
    'push list' => ['push_whitelist_usernames', ['asha']],
    'checks' => ['status_check_contexts', []],
    'no approvals' => ['required_approvals', 0],
    'admins exempt' => ['apply_to_admins', false],
]);

it("names every field of Forgejo 15's branch protection edit", function () {
    expect(array_keys(MainBranch::RULE))->toEqualCanonicalizing([
        'enable_push', 'enable_push_whitelist', 'push_whitelist_usernames', 'push_whitelist_teams', 'push_whitelist_deploy_keys',
        'enable_merge_whitelist', 'merge_whitelist_usernames', 'merge_whitelist_teams', 'enable_status_check', 'status_check_contexts',
        'required_approvals', 'enable_approvals_whitelist', 'approvals_whitelist_username', 'approvals_whitelist_teams',
        'block_on_rejected_reviews', 'block_on_official_review_requests', 'block_on_outdated_branch', 'dismiss_stale_approvals',
        'ignore_stale_approvals', 'require_signed_commits', 'protected_file_patterns', 'unprotected_file_patterns', 'apply_to_admins',
    ]);
});

it('leaves an empty repository alone: a rule on a branch it lacks would stop its first push', function () {
    Http::fake(accForge(stubs: [
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => Http::response(['message' => 'The target couldn\'t be found.'], 404),
        'git.example/api/v1/repos/sdoc/tp-api/branches/*' => Http::response(['message' => 'The target couldn\'t be found.'], 404),
    ]));

    app(RepositoryAccess::class)->sync($this->repo);

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && str_contains($r->url(), '/branch_protections'));
    expect($this->repo->refresh()->protects_main)->toBeFalse()
        ->and($this->repo->access_synced_at)->not->toBeNull();
});

it('keeps the rule another sync made a moment before, instead of failing', function () {
    $reads = 0;
    Http::fake(accForge(stubs: [
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => function () use (&$reads) {
            return ++$reads === 1
                ? Http::response(['message' => 'The target couldn\'t be found.'], 404)
                : Http::response(['rule_name' => 'main', ...MainBranch::RULE]);
        },
        // Forgejo refuses a second rule for the same branch.
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections' => Http::response(['message' => 'Branch protection already exist'], 403),
    ]));

    app(RepositoryAccess::class)->sync($this->repo);

    expect($reads)->toBe(2)
        ->and($this->repo->refresh()->protects_main)->toBeTrue();
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/branch_protections'));
});

it('puts main\'s rule back when someone deleted it on the forge', function () {
    $this->repo->forceFill(['protects_main' => true])->save();
    Http::fake(accForge(stubs: [
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => Http::response(['message' => 'The target couldn\'t be found.'], 404),
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections' => Http::response(['rule_name' => 'main'], 201),
    ]));

    app(RepositoryAccess::class)->sync($this->repo);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/branch_protections')
        && $r->data() === ['rule_name' => 'main', 'branch_name' => 'main', ...MainBranch::RULE]);
    expect($this->repo->refresh()->protects_main)->toBeTrue()
        ->and($this->repo->access_synced_at)->not->toBeNull();
});

it("protects a linked repository's default branch, whatever it is called", function () {
    $this->repo->update(['default_branch' => 'master']);
    Http::fake(accForge(stubs: [
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/master' => Http::response(['message' => 'The target couldn\'t be found.'], 404),
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections' => Http::response(['rule_name' => 'master'], 201),
    ]));

    app(RepositoryAccess::class)->sync($this->repo);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/branch_protections')
        && $r->data() === ['rule_name' => 'master', 'branch_name' => 'master', ...MainBranch::RULE]);
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/branch_protections/main'));
    expect($this->repo->refresh()->protects_main)->toBeTrue();
});

it('marks main unprotected and fails the run when the forge refuses the rule, after everything else', function () {
    $this->repo->forceFill(['protects_main' => true])->save();
    Http::fake(accForge(stubs: [
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => Http::response(['message' => 'Forbidden'], 403),
    ]));

    expect(fn () => app(RepositoryAccess::class)->sync($this->repo))
        ->toThrow(fn (RepositoryAccessFailed $e) => expect([$e->status, $e->operation])->toBe([403, 'patch']));

    expect(accWrites())->toContain('PUT mira admin', 'PUT asha write', 'DELETE Chet')
        ->and($this->repo->refresh()->protects_main)->toBeFalse()
        ->and($this->repo->access_synced_at)->toBeNull();
});

// Importing a repository's collaborators

it('turns the forge\'s collaborators into grants on import', function () {
    GitRepositoryGrant::query()->delete();
    accGrant($this->repo, $this->asha, GitRepositoryGrant::WRITE);
    [, $dina] = accPerson($this->organization, 'dina');               // on the team, read
    [, $farid] = accPerson($this->otherOrganization, 'farid');        // another organization, on the team
    [, $gita] = accPerson($this->otherOrganization, 'gita');          // another organization, not on the team
    [, $hari] = accPerson($this->organization, 'hari', active: false); // deactivated
    [, $ira] = accPerson($this->organization, 'ira');                 // the forge says none
    $this->team->update(['is_cross_organization' => true]);
    $this->team->members()->attach([$dina->id => ['role' => 'member'], $farid->id => ['role' => 'member']]);
    $permission = fn (string $permission) => Http::response(['permission' => $permission, 'role_name' => $permission, 'user' => ['id' => 1, 'login' => 'x']]);
    $base = 'git.example/api/v1/repos/sdoc/tp-api/collaborators';
    // Only the people who could be granted are asked about.
    Http::fake(accForge(['sdoc-bot', 'mira', 'asha', 'Chet', 'contractor', 'Dina', 'farid', 'gita', 'hari', 'ira'], [
        "{$base}/Chet/permission" => $permission('admin'),
        "{$base}/Dina/permission" => $permission('read'),
        "{$base}/farid/permission" => $permission('write'),
        "{$base}/ira/permission" => $permission('none'),
    ]));
    $actor = User::factory()->create();

    $imported = app(RepositoryAccess::class)->importExisting($this->repo, $actor);

    $grants = $this->repo->grants()->with('member')->get()->mapWithKeys(fn (GitRepositoryGrant $g) => [$g->member->name => [$g->permission, $g->granted_by]]);
    expect($imported)->toBe(3)
        ->and($grants->all())->toEqual([
            $this->asha->name => ['write', null],
            $this->chet->name => ['write', $actor->id],
            $dina->name => ['read', $actor->id],
            $farid->name => ['write', $actor->id],
        ])
        ->and(accWrites())->toBe([]);
});

it('skips a collaborator whose permission the forge does not say', function () {
    GitRepositoryGrant::query()->delete();
    Http::fake(accForge(['Chet'], [
        'git.example/api/v1/repos/sdoc/tp-api/collaborators/Chet/permission' => Http::response(['role_name' => 'write']),
    ]));

    expect(app(RepositoryAccess::class)->importExisting($this->repo, null))->toBe(0)
        ->and($this->repo->grants()->count())->toBe(0);
});

// The job

it('logs and returns when the forge fails, without the token', function () {
    Log::spy();
    Http::fake(['git.example/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect bot-token-never-shown')]);

    SyncRepositoryAccess::dispatchSync($this->repo);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === "Couldn't sync a forge repository's access."
        && $context['owner'] === 'sdoc'
        && $context['repository'] === 'tp-api'
        && $context['status'] === 0
        && $context['url'] === 'https://git.example'
        && str_contains($context['cause'], '[token]')
        && ! str_contains((string) json_encode($context), 'never-shown'))->once();
});

it('names the call and login that failed in the job\'s log', function (string $operation) {
    Log::spy();
    $this->repo->forceFill(['protects_main' => true])->save();
    $writes = 'git.example/api/v1/repos/sdoc/tp-api/collaborators/*';
    [$stubs, $expected] = match ($operation) {
        'put' => [[$writes => accCollaboratorWrites(['bikash' => 500])], ['operation' => 'put', 'login' => 'bikash']],
        'delete' => [[$writes => accCollaboratorWrites(['Chet' => 500])], ['operation' => 'delete', 'login' => 'Chet']],
        'patch' => [['git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => Http::response(['message' => 'Internal Server Error'], 500)], ['operation' => 'patch']],
    };
    Http::fake(accForge(stubs: $stubs));

    SyncRepositoryAccess::dispatchSync($this->repo);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === "Couldn't sync a forge repository's access."
        && $context == ['owner' => 'sdoc', 'repository' => 'tp-api', ...$expected, 'status' => 500])->once();
})->with(['put', 'delete', 'patch']);

it('waits for the transaction it was dispatched in to commit', function () {
    Http::fake(accForge());

    DB::transaction(function () {
        SyncRepositoryAccess::dispatchSync($this->repo);

        Http::assertNothingSent();
    });

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT');
});

it('skips a repository unlinked before the job runs', function () {
    Http::fake(accForge());
    $job = new SyncRepositoryAccess($this->repo);
    $this->repo->delete();

    dispatch_sync($job);

    Http::assertNothingSent();
});

it('skips a repository gone before the job runs, even when the job still holds its model', function () {
    Http::fake(accForge());
    $job = new SyncRepositoryAccess($this->repo);
    $this->repo->delete();

    $job->handle(app(RepositoryAccess::class));

    Http::assertNothingSent();
});

it('syncs the repository as it is when the job runs, not as it was queued', function () {
    Http::fake(accForge(name: 'tp-core'));
    $job = new SyncRepositoryAccess($this->repo);
    GitRepository::query()->whereKey($this->repo->id)->update(['name' => 'tp-core', 'full_name' => 'sdoc/tp-core']);

    $job->handle(app(RepositoryAccess::class));

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/repos/sdoc/tp-core/collaborators'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/repos/sdoc/tp-api/'));
});

it('queues one sync per linked repository of a project', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $web = accLinked($this->project, 'tp-web', 502);
    accLinked(Project::factory()->create(['organization_id' => $this->organization->id]), 'other', 503);

    SyncRepositoryAccess::forProject($this->project);

    Queue::assertPushed(SyncRepositoryAccess::class, 2);
    Queue::assertPushed(SyncRepositoryAccess::class, fn (SyncRepositoryAccess $job) => $job->repository->is($this->repo));
    Queue::assertPushed(SyncRepositoryAccess::class, fn (SyncRepositoryAccess $job) => $job->repository->is($web));
});

// The ten-minute command

it('prints one line per repository and fails when one fails', function () {
    $era = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Era Unlinked']);
    accGrant($this->repo, $era, GitRepositoryGrant::READ);
    accLinked($this->project, 'tp-web', 502);
    Http::fake([
        ...accForge(),
        'git.example/api/v1/repos/sdoc/tp-web/collaborators?*' => Http::response(['message' => 'Internal Server Error'], 500),
    ]);

    $this->artisan('git:sync-access')
        ->expectsOutput('sdoc/tp-api: 4 with access, 1 waiting.')
        ->expectsOutput('sdoc/tp-web: failed (forge answered 500).')
        ->doesntExpectOutputToContain('never-shown')
        ->assertFailed();

    $this->artisan('git:sync-access', ['--connection' => $this->connection->id + 1])
        ->expectsOutput('No git connection '.($this->connection->id + 1).'.')
        ->assertFailed();
});

it('syncs only one connection\'s repositories with --connection', function () {
    $opmcm = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    $other = GitConnection::query()->create([
        'organization_id' => $opmcm->id,
        'base_url' => 'https://git.other',
        'owner' => 'opmcm',
        'token' => 'other-token-never-shown',
        'webhook_id' => 98,
        'webhook_secret' => 'other-secret-never-shown',
    ]);
    $repo = accLinked(Project::factory()->create(['organization_id' => $opmcm->id]), 'portal', 601);
    $repo->forceFill(['git_connection_id' => $other->id, 'owner' => 'opmcm', 'full_name' => 'opmcm/portal'])->save();
    Http::fake(accForge());

    $this->artisan('git:sync-access', ['--connection' => $this->connection->id])
        ->expectsOutput('sdoc/tp-api: 4 with access, 0 waiting.')
        ->doesntExpectOutputToContain('opmcm/portal')
        ->assertSuccessful();
});

it('fails the rest of an unreachable forge\'s repositories without waiting for it again', function () {
    Log::spy();
    accLinked($this->project, 'tp-web', 502);
    $calls = 0;
    Http::fake(['git.example/*' => function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 28: timed out bot-token-never-shown');
    }]);

    $this->artisan('git:sync-access')
        ->expectsOutput('sdoc/tp-api: failed (forge answered 0).')
        ->expectsOutput('sdoc/tp-web: failed (forge answered 0).')
        ->doesntExpectOutputToContain('never-shown')
        ->assertFailed();

    expect($calls)->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === "Couldn't sync a forge repository's access."
        && ! str_contains((string) json_encode($context), 'never-shown'))->once();
});

it('reports an unexpected failure by its class alone and goes on to the next repository', function () {
    Exceptions::fake();
    accLinked($this->project, 'tp-web', 502);
    $this->mock(RepositoryAccess::class)->shouldReceive('sync')->twice()->andReturnUsing(function (GitRepository $repo) {
        if ($repo->name === 'tp-api') {
            throw new RuntimeException('update git_repositories set ... (bot-token-never-shown)');
        }

        return ['granted' => ['mira'], 'removed' => [], 'waiting' => []];
    });

    $this->artisan('git:sync-access')
        ->expectsOutput('sdoc/tp-api: failed (RuntimeException).')
        ->expectsOutput('sdoc/tp-web: 1 with access, 0 waiting.')
        ->doesntExpectOutputToContain('never-shown')
        ->assertFailed();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The git access sync of sdoc/tp-api failed: RuntimeException.');
    Exceptions::assertNotReported(fn (Throwable $e) => str_contains($e->getMessage(), 'never-shown'));
});

it('runs every ten minutes, one at a time, a killed run\'s lock lapsing after 15 minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'git:sync-access'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(15);
});

// The nightly reconcile

/**
 * What git:reconcile asks of sdoc besides the access sync: an intact
 * webhook, the repository unchanged with no new history, an Owners team and
 * an organization holding only the bot.
 *
 * @return array<string, mixed>
 */
function accReconcileForge(): array
{
    $api = 'git.example/api/v1';
    $forgeRepo = [
        'id' => 501,
        'owner' => ['id' => 9, 'login' => 'sdoc', 'username' => 'sdoc'],
        'name' => 'tp-api',
        'full_name' => 'sdoc/tp-api',
        'empty' => false,
        'private' => true,
        'html_url' => 'https://git.example/sdoc/tp-api',
        'url' => 'https://git.example/api/v1/repos/sdoc/tp-api',
        'ssh_url' => 'git@git.example:sdoc/tp-api.git',
        'clone_url' => 'https://git.example/sdoc/tp-api.git',
        'default_branch' => 'main',
        'archived' => false,
        'permissions' => ['admin' => true, 'push' => true, 'pull' => true],
    ];
    $empty = accPage([]);

    return [
        "{$api}/orgs/sdoc/hooks/99" => fn () => Http::response([
            'id' => 99,
            'type' => 'forgejo',
            'branch_filter' => '*',
            'config' => ['url' => route('git.webhook', GitConnection::query()->where('owner', 'sdoc')->sole()), 'content_type' => 'json'],
            'events' => GitWebhookDelivery::EVENTS,
            'active' => true,
        ]),
        "{$api}/repositories/501" => Http::response($forgeRepo),
        "{$api}/repos/sdoc/tp-api" => Http::response($forgeRepo),
        "{$api}/repos/sdoc/tp-api/pulls?*" => $empty,
        "{$api}/repos/sdoc/tp-api/branches?*" => $empty,
        "{$api}/repos/sdoc/tp-api/commits?*" => $empty,
        "{$api}/orgs/sdoc/teams?*" => accPage([['id' => 5, 'name' => 'Owners']]),
        "{$api}/teams/5/members?*" => accLogins(['sdoc-bot']),
        "{$api}/orgs/sdoc/members?*" => accLogins(['sdoc-bot']),
    ];
}

it('runs an access step per repository in the nightly reconcile', function () {
    Http::fake([...accReconcileForge(), ...accForge()]);

    $this->artisan('git:reconcile')
        ->expectsOutput('sdoc at https://git.example: reconciled 1 repository.')
        ->assertSuccessful();

    expect(accWrites())->toContain('PUT mira admin', 'PUT asha write', 'DELETE Chet')
        ->and($this->repo->refresh()->access_synced_at?->equalTo(now()))->toBeTrue();
});

it('writes no access to a repository the reconcile found gone', function () {
    Http::fake([
        ...accReconcileForge(),
        ...accForge(),
        // Gone from the forge; the bot still owns the organization, so it is unlinked.
        'git.example/api/v1/repositories/501' => Http::response(['message' => 'Not Found'], 404),
        'git.example/api/v1/user' => Http::response(['id' => 1, 'login' => 'sdoc-bot']),
        'git.example/api/v1/users/sdoc-bot/orgs/sdoc/permissions' => Http::response(['is_owner' => true, 'is_admin' => true]),
    ]);

    $this->artisan('git:reconcile')
        ->expectsOutput('sdoc at https://git.example: reconciled 0 repositories, unlinked 1.')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/collaborators'));
    expect(GitRepository::query()->count())->toBe(0);
});

it('names the login in a failed access step\'s log', function () {
    Log::spy();
    Http::fake([
        ...accReconcileForge(),
        ...accForge(stubs: ['git.example/api/v1/repos/sdoc/tp-api/collaborators/*' => accCollaboratorWrites(['asha' => 403])]),
    ]);

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc/tp-api access: The forge answered 403 (put asha)')
        ->assertFailed();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'A git reconcile step failed.'
        && $context == ['owner' => 'sdoc', 'step' => 'sdoc/tp-api access', 'operation' => 'put', 'login' => 'asha', 'status' => 403])->once();
});

it('reports a failed access step like the reconcile\'s other steps', function () {
    Http::fake([
        ...accReconcileForge(),
        ...accForge(stubs: ['git.example/api/v1/repos/sdoc/tp-api/collaborators?*' => Http::response(['message' => 'Internal Server Error'], 500)]),
    ]);

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc/tp-api access: The forge answered 500')
        ->doesntExpectOutputToContain('never-shown')
        ->assertFailed();
});
