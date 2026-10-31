<?php

use App\Models\Member;
use App\Queries\Work\WorkActivityQuery;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Workspace\Work\WorkFixture as W;

/*
 * The work reports on the web: today's work (/workspace/work), a team's
 * week (/workspace/work/team) and one person's week
 * (/workspace/people/{member}/work). Today is Wednesday 28 October 2026;
 * the world is WorkFixture's.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 15:00:00'));
    $this->w = W::make();
});

/** @return array<string, mixed> */
function workPage(TestResponse $response, string $component): array
{
    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component($component));

    return $response->viewData('page')['props'];
}

/**
 * @param  array<string, mixed>  $page
 * @return array<string, array<string, mixed>> rows by person name
 */
function workRows(array $page): array
{
    return collect($page['people'])->keyBy(fn (array $row): string => $row['member']['name'])->all();
}

// ---- Attribution ----

it('credits a closed or started task to whoever changed its status, on that day only', function () {
    $closed = W::task($this->w->nagarik, 'Retry the OTP on slow networks');
    $started = W::task($this->w->nagarik, 'Ask before sending push notifications');
    $byAnil = W::task($this->w->nagarik, 'Show when the token expires');
    $yesterday = W::task($this->w->nagarik, 'An old one');

    W::status($closed, $this->w->suman, 'done', '2026-10-28 10:00:00');
    W::status($started, $this->w->suman, 'in_progress', '2026-10-28 11:00:00');
    W::status($byAnil, $this->w->anil, 'done', '2026-10-28 12:00:00');
    W::status($yesterday, $this->w->suman, 'done', '2026-10-27 23:59:00');

    $rows = workRows(workPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day'));
    $suman = $rows['Suman Karki'];

    expect(array_column($suman['closed'], 'title'))->toBe(['Retry the OTP on slow networks'])
        ->and($suman['closed'][0])->toMatchArray([
            'id' => $closed->id,
            'code' => "NAGARIK-{$closed->number}",
            'project' => 'Nagarik App',
            'project_slug' => 'nagarik',
            'slug' => $closed->slug,
            'status' => 'done',
        ])
        ->and(array_column($suman['started'], 'title'))->toBe(['Ask before sending push notifications'])
        ->and(array_column($rows['Anil Gurung']['closed'], 'title'))->toBe(['Show when the token expires'])
        ->and($suman['attendance'])->toBeNull();
});

it('counts a task closed, reopened and closed again in the range once, as its latest close', function () {
    $task = W::task($this->w->nagarik, 'Flaky one');
    W::status($task, $this->w->suman, 'done', '2026-10-28 09:00:00');
    W::status($task, $this->w->suman, 'in_progress', '2026-10-28 10:00:00');
    W::status($task, $this->w->suman, 'failed', '2026-10-28 11:00:00');

    $suman = workRows(workPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day'))['Suman Karki'];

    expect($suman['closed'])->toHaveCount(1)
        ->and($suman['closed'][0]['status'])->toBe('failed')
        ->and($suman['started'])->toHaveCount(1);
});

it('reads the status changes the activity log really records', function () {
    $task = W::task($this->w->nagarik, 'Real change', 'not_started');

    $this->actingAs($this->w->suman);
    $task->update(['status' => 'in_progress']);

    $suman = workRows(workPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day'))['Suman Karki'];

    expect(array_column($suman['started'], 'id'))->toBe([$task->id]);
});

it('leaves trashed tasks out', function () {
    $task = W::task($this->w->nagarik, 'Gone');
    W::status($task, $this->w->suman, 'done', '2026-10-28 09:00:00');
    $task->delete();

    $suman = workRows(workPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day'))['Suman Karki'];

    expect($suman['closed'])->toBe([])->and($suman['hidden_closed'])->toBe(0);
});

// ---- Visibility ----

it('counts a task in a project the viewer cannot see, and never names it', function () {
    $secret = W::task($this->w->secret, 'Hidden plan');
    W::status($secret, $this->w->suman, 'done', '2026-10-28 09:00:00');

    $response = $this->actingAs($this->w->director)->get('/workspace/work');
    $suman = workRows(workPage($response, 'Work/Day'))['Suman Karki'];

    expect($suman['closed'])->toBe([])
        ->and($suman['hidden_closed'])->toBe(1)
        ->and($suman['nothing_recorded'])->toBeFalse();
    $response->assertDontSee('Hidden plan');

    $asAdmin = workRows(workPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day'))['Suman Karki'];

    expect(array_column($asAdmin['closed'], 'title'))->toBe(['Hidden plan']);
});

it('counts commits in a repository whose code the viewer cannot see, and never names it', function () {
    W::commit($this->w->nagarikRepo, $this->w->suman, 'a1', '2026-10-28 09:00:00');
    W::commit($this->w->secretRepo, $this->w->suman, 'a2', '2026-10-28 10:00:00');
    W::commit($this->w->secretRepo, $this->w->suman, 'a3', '2026-10-28 11:00:00');

    $response = $this->actingAs($this->w->director)->get('/workspace/work');
    $code = workRows(workPage($response, 'Work/Day'))['Suman Karki']['commits'];

    expect($code)->toBe([
        'count' => 3,
        'code_added' => 30,
        'code_removed' => 6,
        'comment_added' => 9,
        'comment_removed' => 3,
        'repositories' => [['name' => 'nagarik', 'count' => 1]],
        'hidden_commits' => 2,
    ]);
    $response->assertDontSee('secret-api');

    $asAdmin = workRows(workPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day'))['Suman Karki']['commits'];

    expect($asAdmin['repositories'])->toBe([['name' => 'secret-api', 'count' => 2], ['name' => 'nagarik', 'count' => 1]])
        ->and($asAdmin['hidden_commits'])->toBe(0);
});

// ---- Commits and pull requests ----

it('leaves merge commits, unreachable commits and other days out', function () {
    W::commit($this->w->nagarikRepo, $this->w->suman, 'ok', '2026-10-28 09:00:00');
    W::commit($this->w->nagarikRepo, $this->w->suman, 'merge', '2026-10-28 09:30:00', ['is_merge' => true]);
    W::commit($this->w->nagarikRepo, $this->w->suman, 'gone', '2026-10-28 10:00:00', ['unreachable_at' => '2026-10-28 12:00:00']);
    W::commit($this->w->nagarikRepo, $this->w->suman, 'old', '2026-10-27 18:00:00');

    $page = workPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day');

    expect(workRows($page)['Suman Karki']['commits']['count'])->toBe(1)
        ->and($page['summary']['commits'])->toBe(1)
        ->and($page['summary']['committers'])->toBe(1);
});

it('counts the pull requests merged that day by the git username, in any case', function () {
    W::pullRequest($this->w->nagarikRepo, 1, 'SUMAN', '2026-10-28 14:00:00');
    W::pullRequest($this->w->nagarikRepo, 2, 'suman', null);
    W::pullRequest($this->w->nagarikRepo, 3, 'suman', '2026-10-27 14:00:00');
    W::pullRequest($this->w->nagarikRepo, 4, 'anil', '2026-10-28 14:00:00');
    W::pullRequest($this->w->secretRepo, 5, 'suman', '2026-10-28 15:00:00');

    $asDirector = workRows(workPage($this->actingAs($this->w->director)->get('/workspace/work'), 'Work/Day'));

    expect($asDirector['Suman Karki']['pull_requests_merged'])->toBe([[
        'title' => 'Change 1',
        'number' => 1,
        'repository' => 'nagarik',
        'url' => 'https://git.example/sdoc/nagarik/pulls/1',
        'merged_at' => CarbonImmutable::parse('2026-10-28 14:00:00')->toIso8601String(),
    ]])
        ->and($asDirector['Suman Karki']['hidden_pull_requests'])->toBe(1)
        ->and(array_column($asDirector['Anil Gurung']['pull_requests_merged'], 'number'))->toBe([4]);
});

it('sums the day and names who did nothing recorded', function () {
    $task = W::task($this->w->nagarik, 'One');
    W::status($task, $this->w->suman, 'done', '2026-10-28 09:00:00');
    W::commit($this->w->nagarikRepo, $this->w->anil, 'a1', '2026-10-28 09:00:00');
    W::pullRequest($this->w->nagarikRepo, 1, 'anil', '2026-10-28 14:00:00');

    $page = workPage($this->actingAs($this->w->director)->get('/workspace/work'), 'Work/Day');

    expect($page['summary'])->toBe([
        'people' => 2,
        'tasks_closed' => 1,
        'tasks_started' => 0,
        'commits' => 1,
        'committers' => 1,
        'pull_requests_merged' => 1,
        'nothing_recorded' => 0,
    ])
        ->and($page['date'])->toBe('2026-10-28')
        ->and($page['filters'])->toBe(['date' => '2026-10-28', 'organization' => $this->w->sdoc->id, 'under' => null]);

    $other = workPage($this->actingAs($this->w->director)->get('/workspace/work?date=2026-10-20'), 'Work/Day');

    expect($other['summary']['nothing_recorded'])->toBe(2)
        ->and(collect($other['people'])->pluck('nothing_recorded')->all())->toBe([true, true]);
});

// ---- Who sees what ----

it("shows an organization's admin everyone in it, by name", function () {
    $page = workPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day');

    expect(collect($page['people'])->pluck('member.name')->all())
        ->toBe(['Anil Gurung', 'Asha Admin', 'Dipak Director', 'Ek ED', 'Pema Peer', 'Suman Karki'])
        ->and($page['options']['teams'])->toBe([
            ['id' => W::member($this->w->director)->id, 'name' => 'Dipak Director', 'designation' => null],
            ['id' => W::member($this->w->ed)->id, 'name' => 'Ek ED', 'designation' => null],
        ])
        ->and($page['options']['organizations'])->toBe([]);
});

it('shows someone above people only the people below them', function () {
    $director = workPage($this->actingAs($this->w->director)->get('/workspace/work'), 'Work/Day');
    $ed = workPage($this->actingAs($this->w->ed)->get('/workspace/work'), 'Work/Day');

    expect(collect($director['people'])->pluck('member.name')->all())->toBe(['Anil Gurung', 'Suman Karki'])
        ->and(collect($ed['people'])->pluck('member.name')->all())->toBe(['Anil Gurung', 'Dipak Director', 'Pema Peer', 'Suman Karki']);
});

it('narrows to someone\'s team for whoever stands over them, and refuses a team outside the view', function () {
    $director = W::member($this->w->director);

    $page = workPage($this->actingAs($this->w->ed)->get("/workspace/work?under={$director->id}"), 'Work/Day');

    expect(collect($page['people'])->pluck('member.name')->all())->toBe(['Anil Gurung', 'Dipak Director', 'Suman Karki'])
        ->and($page['under'])->toBe(['id' => $director->id, 'name' => 'Dipak Director']);

    $this->actingAs($this->w->director)->get('/workspace/work?under='.W::member($this->w->peer)->id)->assertForbidden();
    $this->actingAs($this->w->director)->get('/workspace/work?under='.W::member($this->w->ed)->id)->assertForbidden();
    $this->actingAs($this->w->director)->get('/workspace/work?under=999999')->assertForbidden();
    $this->actingAs($this->w->director)->get('/workspace/work?under=abc')->assertForbidden();
    $this->actingAs($this->w->admin)->get('/workspace/work?under='.W::member($this->w->mofAdmin)->id)->assertForbidden();
});

it('refuses the lists to someone with nobody below them, and to another organization', function () {
    $this->actingAs($this->w->suman)->get('/workspace/work')->assertForbidden();
    $this->actingAs($this->w->suman)->get('/workspace/work/team')->assertForbidden();
    $this->actingAs($this->w->mofAdmin)->get("/workspace/work?organization={$this->w->sdoc->id}")->assertForbidden();
    $this->actingAs($this->w->mofAdmin)->get("/workspace/work/team?organization={$this->w->sdoc->id}")->assertForbidden();
});

it('lets a super-admin open any organization, the first by name unless one is picked', function () {
    $first = workPage($this->actingAs($this->w->boss)->get('/workspace/work'), 'Work/Day');
    $sdoc = workPage($this->actingAs($this->w->boss)->get("/workspace/work?organization={$this->w->sdoc->id}"), 'Work/Day');

    expect($first['organization_id'])->toBe($this->w->mof->id)
        ->and(collect($first['people'])->pluck('member.name')->all())->toBe(['Mohan Admin'])
        ->and(collect($first['options']['organizations'])->pluck('name')->all())->toBe(['MoF', 'SDOC'])
        ->and($sdoc['organization_id'])->toBe($this->w->sdoc->id);

    $this->actingAs($this->w->boss)->get('/workspace/work?organization=999999')->assertSessionHasErrors('organization');
});

it("shows a person's work to them and to everyone above them, never to a peer, someone below or another organization", function () {
    $suman = W::member($this->w->suman);

    foreach ([$this->w->suman, $this->w->director, $this->w->ed, $this->w->admin, $this->w->boss] as $viewer) {
        $this->actingAs($viewer)->get("/workspace/people/{$suman->id}/work")->assertOk();
    }

    foreach ([$this->w->anil, $this->w->peer, $this->w->mofAdmin] as $viewer) {
        $this->actingAs($viewer)->get("/workspace/people/{$suman->id}/work")->assertForbidden();
    }

    $this->actingAs($this->w->suman)->get('/workspace/people/'.W::member($this->w->director)->id.'/work')->assertForbidden();
});

it('gives a deactivated person above others no view of them, and signs them out', function () {
    W::member($this->w->director)->update(['is_active' => false]);
    WorkspaceAccess::flush();
    $suman = W::member($this->w->suman);

    expect(WorkspaceAccess::canViewPersonOf($this->w->director, $suman))->toBeFalse()
        ->and(WorkspaceAccess::canViewTeamWork($this->w->director, $this->w->sdoc->id))->toBeFalse();

    $this->actingAs($this->w->director)->get("/workspace/people/{$suman->id}/work")->assertRedirect();
    $this->assertGuest();
});

// ---- A person's week ----

it("shows a person's week: header, tasks with overdue, code, pull requests", function () {
    $finished = W::task($this->w->nagarik, 'Finished this week');
    $hidden = W::task($this->w->secret, 'Secret finished');
    $overdue = W::task($this->w->nagarik, 'Bid opening report export', 'in_progress', '2026-10-26');
    $secretOverdue = W::task($this->w->secret, 'Secret overdue', 'in_progress', '2026-10-20');
    $closedLate = W::task($this->w->nagarik, 'Closed late', 'done', '2026-10-20');
    W::assign($overdue, $this->w->suman);
    W::assign($secretOverdue, $this->w->suman);
    W::assign($closedLate, $this->w->suman);
    W::status($finished, $this->w->suman, 'done', '2026-10-26 10:00:00');
    W::status($hidden, $this->w->suman, 'done', '2026-10-27 10:00:00');
    W::status($finished, $this->w->suman, 'done', '2026-10-25 10:00:00'); // last week: the Sunday before
    W::commit($this->w->nagarikRepo, $this->w->suman, 'a1', '2026-10-27 09:00:00');
    W::pullRequest($this->w->nagarikRepo, 7, 'suman', '2026-10-27 14:00:00');

    $suman = W::member($this->w->suman);
    $page = workPage($this->actingAs($this->w->director)->get("/workspace/people/{$suman->id}/work"), 'Work/Person');

    expect($page['member'])->toBe([
        'id' => $suman->id,
        'name' => 'Suman Karki',
        'designation' => null,
        'organization_id' => $this->w->sdoc->id,
        'reports_to' => ['id' => W::member($this->w->director)->id, 'name' => 'Dipak Director'],
    ])
        ->and($page['range'])->toBe(['from' => '2026-10-26', 'to' => '2026-10-28'])
        ->and($page['filters'])->toBe(['from' => '2026-10-26', 'to' => '2026-10-28'])
        ->and($page['attendance'])->toBeNull()
        ->and(array_column($page['tasks']['closed'], 'title'))->toBe(['Finished this week'])
        ->and(array_column($page['tasks']['overdue'], 'title'))->toBe(['Bid opening report export'])
        ->and($page['tasks']['overdue'][0]['deadline_at'])->toStartWith('2026-10-26')
        ->and($page['tasks']['counts'])->toBe([
            'closed' => 2,
            'started' => 0,
            'overdue' => 2,
            'hidden_closed' => 1,
            'hidden_started' => 0,
            'hidden_overdue' => 1,
        ])
        ->and($page['code']['count'])->toBe(1)
        ->and(array_column($page['pull_requests_merged'], 'number'))->toBe([7]);
});

it('validates the range: the end not before the start, at most 92 days', function () {
    $suman = W::member($this->w->suman);

    $this->actingAs($this->w->director)->get("/workspace/people/{$suman->id}/work?from=2026-10-28&to=2026-10-20")->assertSessionHasErrors('to');
    $this->actingAs($this->w->director)->get("/workspace/people/{$suman->id}/work?from=2026-07-01&to=2026-10-28")->assertSessionHasErrors('to');
    $this->actingAs($this->w->director)->get("/workspace/people/{$suman->id}/work?from=2026-07-29&to=2026-10-28")->assertOk();
    $this->actingAs($this->w->director)->get("/workspace/people/{$suman->id}/work?from=last-week")->assertSessionHasErrors('from');
});

// ---- A team's week ----

it("lists a team's week by name, one row of figures each", function () {
    $task = W::task($this->w->nagarik, 'Done');
    $late = W::task($this->w->nagarik, 'Late', 'in_progress', '2026-10-27');
    W::assign($late, $this->w->anil);
    W::status($task, $this->w->suman, 'done', '2026-10-27 10:00:00');
    W::commit($this->w->nagarikRepo, $this->w->suman, 'a1', '2026-10-26 09:00:00', ['code_added' => 500]);
    W::commit($this->w->secretRepo, $this->w->suman, 'a2', '2026-10-26 10:00:00', ['code_added' => 5]);

    $page = workPage($this->actingAs($this->w->director)->get('/workspace/work/team'), 'Work/Team');
    $rows = workRows($page);

    expect(array_keys($rows))->toBe(['Anil Gurung', 'Suman Karki'])
        ->and($rows['Suman Karki'])->toMatchArray([
            'attendance' => null,
            'tasks_closed' => 1,
            'tasks_started' => 0,
            'overdue' => 0,
            'commits' => 2,
            'code_added' => 505,
            'code_removed' => 4,
            'pull_requests_merged' => 0,
        ])
        ->and($rows['Anil Gurung']['overdue'])->toBe(1)
        ->and($page['range'])->toBe(['from' => '2026-10-26', 'to' => '2026-10-28'])
        ->and($page['filters'])->toBe(['from' => '2026-10-26', 'to' => '2026-10-28', 'organization' => $this->w->sdoc->id, 'under' => null]);
});

// ---- Scale ----

it('reads the work of any number of people in the same number of queries', function () {
    $countFor = function (int $people): int {
        $members = collect(range(1, $people))->map(function (int $i) use ($people): Member {
            $user = W::person($this->w->sdoc, "Person {$people}-{$i}", boss: $this->w->director, gitUsername: "person{$people}x{$i}");
            $task = W::task($this->w->nagarik, "Task {$i}", 'in_progress', '2026-10-20');
            W::assign($task, $user);
            W::status($task, $user, 'done', '2026-10-27 10:00:00');
            W::commit($this->w->nagarikRepo, $user, "sha-{$people}-{$i}", '2026-10-27 11:00:00');
            W::pullRequest($this->w->nagarikRepo, $people * 100 + $i, "person{$people}x{$i}", '2026-10-27 12:00:00');

            return W::member($user);
        });

        WorkspaceAccess::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work = app(WorkActivityQuery::class)->forMembers($this->w->director, $members, CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-28'), withOverdue: true);
        DB::disableQueryLog();

        expect($work)->toHaveCount($people)
            ->and(collect($work)->sum(fn (array $activity): int => count($activity['tasks_closed'])))->toBe($people);

        return count(DB::getQueryLog());
    };

    expect($countFor(2))->toBe($countFor(7));
});
