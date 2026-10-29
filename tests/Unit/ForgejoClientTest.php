<?php

use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const FORGE = 'https://git.example.test/api/v1';

function forgeClient(): ForgejoClient
{
    return new ForgejoClient('https://git.example.test', 'secret-bot-token');
}

/** A list endpoint with only a first page of $data, as a real forge answers. */
function firstPage(array $data): Closure
{
    return fn (Request $request) => Http::response(str_contains($request->url(), 'page=1&') || str_ends_with($request->url(), 'page=1') ? $data : []);
}

/** @return list<array{id: int}> */
function rows(int $count, int $from = 1): array
{
    return array_map(fn (int $i) => ['id' => $i], range($from, $from + $count - 1));
}

it('creates the organization hook and returns its id', function () {
    Http::fake([FORGE.'/orgs/sdoc/hooks' => Http::response(['id' => 5], 201)]);

    expect(forgeClient()->createOrganizationHook('sdoc', 'https://app.test/git/webhooks/1', 's3cret'))->toBe(5);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === [
        'type' => 'forgejo',
        'active' => true,
        'branch_filter' => '*',
        'events' => ['push', 'create', 'delete', 'pull_request', 'repository'],
        'config' => ['url' => 'https://app.test/git/webhooks/1', 'content_type' => 'json', 'secret' => 's3cret'],
    ] && $r->hasHeader('Authorization', 'token secret-bot-token'));
});

it('throws the status when the hook cannot be created, without the secret', function () {
    Http::fake([FORGE.'/orgs/sdoc/hooks' => Http::response([], 403)]);

    try {
        forgeClient()->createOrganizationHook('sdoc', 'https://app.test/x', 's3cret');
        $this->fail('Expected a ForgejoException.');
    } catch (ForgejoException $e) {
        expect($e->status)->toBe(403)->and($e->getMessage())->not->toContain('s3cret')->not->toContain('secret-bot-token');
    }
});

it('reads an organization hook, gives null when it is gone, and refuses an answer that is not that hook', function () {
    Http::fake([
        FORGE.'/orgs/sdoc/hooks/1' => Http::response(['id' => 1, 'active' => true]),
        FORGE.'/orgs/sdoc/hooks/2' => Http::response([], 404),
        FORGE.'/orgs/sdoc/hooks/3' => Http::response([], 500),
        FORGE.'/orgs/sdoc/hooks/4' => Http::response('<html>Sign in</html>'),
        FORGE.'/orgs/sdoc/hooks/5' => Http::response(['id' => 6, 'active' => true]),
    ]);

    expect(forgeClient()->organizationHook('sdoc', 1))->toBe(['id' => 1, 'active' => true])
        ->and(forgeClient()->organizationHook('sdoc', 2))->toBeNull();
    // An answer about another hook is not this one.
    foreach ([3 => 500, 4 => 502, 5 => 502] as $id => $status) {
        try {
            forgeClient()->organizationHook('sdoc', $id);
            $this->fail('Expected a ForgejoException.');
        } catch (ForgejoException $e) {
            expect($e->status)->toBe($status);
        }
    }
});

it('puts every setting of an organization hook back, with the secret', function () {
    Http::fake([FORGE.'/orgs/sdoc/hooks/5' => Http::response(['id' => 5])]);

    forgeClient()->updateOrganizationHook('sdoc', 5, 'https://app.test/git/webhooks/1', 's3cret');

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === FORGE.'/orgs/sdoc/hooks/5' && $r->data() === [
        'active' => true,
        'branch_filter' => '*',
        'events' => ['push', 'create', 'delete', 'pull_request', 'repository'],
        'config' => ['url' => 'https://app.test/git/webhooks/1', 'content_type' => 'json', 'secret' => 's3cret'],
    ]);
});

it('deletes an organization hook and accepts a 404', function () {
    Http::fake([FORGE.'/orgs/sdoc/hooks/1' => Http::response('', 204), FORGE.'/orgs/sdoc/hooks/2' => Http::response([], 404), FORGE.'/orgs/sdoc/hooks/3' => Http::response([], 403)]);

    forgeClient()->deleteOrganizationHook('sdoc', 1);
    forgeClient()->deleteOrganizationHook('sdoc', 2);

    expect(fn () => forgeClient()->deleteOrganizationHook('sdoc', 3))->toThrow(ForgejoException::class);
});

it('pages through a list 50 at a time and stops at the max', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        return Http::response(rows(50, ((int) $q['page'] - 1) * 50 + 1));
    });

    $repos = forgeClient()->organizationRepositories('sdoc', 120);

    expect($repos)->toHaveCount(120)->and($repos[119]['id'])->toBe(120);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/orgs/sdoc/repos?') && str_contains($r->url(), 'limit=50'));
});

it('keeps paging past a short page when the forge caps pages below 50', function () {
    Http::fake([FORGE.'/repos/sdoc/app/branches*' => Http::sequence()->push(rows(30))->push(rows(30, 31))->push(rows(5, 61))->push([])]);

    expect(forgeClient()->branches('sdoc', 'app'))->toHaveCount(65);
    Http::assertSentCount(4);
});

it('stops when the Link header has no next page', function () {
    Http::fake([FORGE.'/repos/sdoc/app/branches*' => Http::sequence()
        ->push(rows(30), 200, ['Link' => '<https://x/?page=2>; rel="next", <https://x/?page=2>; rel="last"'])
        ->push(rows(5, 31), 200, ['Link' => '<https://x/?page=1>; rel="prev", <https://x/?page=1>; rel="first"'])]);

    expect(forgeClient()->branches('sdoc', 'app'))->toHaveCount(35);
    Http::assertSentCount(2);
});

it('gives up after 200 pages when the forge ignores the page parameter', function () {
    Http::fake([FORGE.'/repos/sdoc/app/branches*' => Http::response(rows(10))]);

    expect(forgeClient()->branches('sdoc', 'app', PHP_INT_MAX))->toHaveCount(2000);
    Http::assertSentCount(200);
});

it('throws when a created hook comes back without an id', function (array $body) {
    Http::fake([FORGE.'/orgs/sdoc/hooks' => Http::response($body, 201)]);

    try {
        forgeClient()->createOrganizationHook('sdoc', 'https://app.test/x', 's3cret');
        $this->fail('Expected a ForgejoException.');
    } catch (ForgejoException $e) {
        expect($e->status)->toBe(502)->and($e->getMessage())->toBe('The forge did not return a webhook id.');
    }
})->with([[[]], [['id' => 0]], [['ok' => true]]]);

it('returns an empty list for an empty page', function () {
    Http::fake([FORGE.'/repos/sdoc/app/pulls*' => Http::response([])]);

    expect(forgeClient()->pullRequests('sdoc', 'app'))->toBe([]);
});

it('reads one repository and throws 404 when it is missing', function () {
    Http::fake([FORGE.'/repos/sdoc/app' => Http::response(['id' => 3, 'name' => 'app']), FORGE.'/repos/sdoc/nope' => Http::response([], 404)]);

    expect(forgeClient()->repository('sdoc', 'app')['id'])->toBe(3);
    try {
        forgeClient()->repository('sdoc', 'nope');
        $this->fail('Expected a ForgejoException.');
    } catch (ForgejoException $e) {
        expect($e->status)->toBe(404);
    }
});

it('asks for commits without stats, files or verification', function () {
    Http::fake([FORGE.'/repos/sdoc/app/commits*' => firstPage([['sha' => 'a1']])]);

    $commits = forgeClient()->commits('sdoc', 'app', 'main', CarbonImmutable::parse('2026-10-01T00:00:00Z'));

    expect($commits)->toHaveCount(1);
    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q['sha'] === 'main' && $q['stat'] === 'false' && $q['files'] === 'false'
            && $q['verification'] === 'false' && str_starts_with($q['since'], '2026-10-01T00:00:00');
    });
});

it('stops listing a history after the page that reaches back past since, as the forge ignores it', function () {
    $commit = fn (string $sha, string $date) => ['sha' => $sha, 'commit' => ['committer' => ['date' => $date], 'author' => ['date' => $date]]];
    Http::fake([FORGE.'/repos/sdoc/app/commits*' => Http::sequence()
        ->push([$commit('c3', '2026-10-03T10:00:00+05:45'), $commit('c2', '2026-10-02T10:00:00+05:45')])
        ->push([$commit('c1', '2026-10-01T10:00:00+05:45'), $commit('c0', '2026-09-20T10:00:00+05:45')])
        ->push([$commit('b9', '2026-09-10T10:00:00+05:45')])]);

    $commits = forgeClient()->commits('sdoc', 'app', 'main', CarbonImmutable::parse('2026-10-01T00:00:00+05:45'));

    expect(array_column($commits, 'sha'))->toBe(['c3', 'c2', 'c1', 'c0']);
    Http::assertSentCount(2);
});

it('reads a repository by its forge id, 404s when it is gone, and refuses an answer that is not that repository', function () {
    Http::fake([
        FORGE.'/repositories/501' => Http::response(['id' => 501, 'name' => 'app']),
        FORGE.'/repositories/502' => Http::response(['message' => 'Not Found'], 404),
        FORGE.'/repositories/503' => Http::response('<html>Sign in</html>'),
    ]);

    expect(forgeClient()->repositoryById(501)['name'])->toBe('app');
    foreach ([502 => 404, 503 => 502] as $id => $status) {
        try {
            forgeClient()->repositoryById($id);
            $this->fail('Expected a ForgejoException.');
        } catch (ForgejoException $e) {
            expect($e->status)->toBe($status);
        }
    }
});

it('asks for all pull requests, most recently updated first', function () {
    Http::fake([FORGE.'/repos/sdoc/app/pulls*' => firstPage([['id' => 1]])]);

    forgeClient()->pullRequests('sdoc', 'app');

    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q['state'] === 'all' && $q['sort'] === 'recentupdate';
    });
});

it('links the external tracker', function () {
    config(['app.url' => 'https://tasks.example.test']);
    Http::fake([FORGE.'/repos/sdoc/app' => Http::response([])]);

    forgeClient()->setExternalTracker('sdoc', 'app', 'https://tasks.example.test/go/sdoc/{index}');

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === [
        'has_issues' => true,
        'external_tracker' => [
            'external_tracker_url' => 'https://tasks.example.test',
            'external_tracker_format' => 'https://tasks.example.test/go/sdoc/{index}',
            'external_tracker_style' => 'alphanumeric',
        ],
    ]);
});

it('switches a repository back to the forge\'s own issue tracker', function () {
    Http::fake([FORGE.'/repos/sdoc/app' => Http::response([])]);

    forgeClient()->useInternalTracker('sdoc', 'app');

    // No external_tracker: Forgejo then turns on its built-in tracker and drops the external one.
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === FORGE.'/repos/sdoc/app' && $r->data() === ['has_issues' => true]);
});

it('lists organization members and team members as logins', function () {
    Http::fake([
        FORGE.'/orgs/sdoc/members*' => firstPage([['login' => 'asha'], ['login' => 'bina']]),
        FORGE.'/teams/9/members*' => firstPage([['login' => 'asha']]),
    ]);

    expect(forgeClient()->organizationMembers('sdoc'))->toBe(['asha', 'bina'])
        ->and(forgeClient()->teamMembers(9))->toBe(['asha']);
});

it('finds the Owners team, and 404s when there is none', function () {
    Http::fake([
        FORGE.'/orgs/sdoc/teams*' => firstPage([['id' => 4, 'name' => 'Readers'], ['id' => 5, 'name' => 'Owners']]),
        FORGE.'/orgs/bare/teams*' => firstPage([['id' => 4, 'name' => 'Readers']]),
    ]);

    expect(forgeClient()->ownersTeamId('sdoc'))->toBe(5);
    try {
        forgeClient()->ownersTeamId('bare');
        $this->fail('Expected a ForgejoException.');
    } catch (ForgejoException $e) {
        expect($e->status)->toBe(404);
    }
});

it('adds and removes team members, accepting a 404 on remove', function () {
    Http::fake([
        FORGE.'/teams/9/members/asha' => Http::response('', 204),
        FORGE.'/teams/9/members/gone' => Http::response([], 404),
    ]);

    forgeClient()->addTeamMember(9, 'asha');
    forgeClient()->removeTeamMember(9, 'asha');
    forgeClient()->removeTeamMember(9, 'gone');

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === FORGE.'/teams/9/members/asha');
    expect(fn () => forgeClient()->addTeamMember(9, 'gone'))->toThrow(ForgejoException::class);
});

it('reports status 0 and keeps the cause when the forge cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('boom'));

    try {
        forgeClient()->branches('sdoc', 'app');
        $this->fail('Expected a ForgejoException.');
    } catch (ForgejoException $e) {
        expect($e->status)->toBe(0)->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});
