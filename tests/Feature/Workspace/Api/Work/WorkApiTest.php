<?php

use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workspace\Work\WorkFixture as W;

/*
 * The JSON twins of the work reports, through Sanctum: the same requests
 * and queries as the pages, answered as `{data, filters}`. Today is
 * Wednesday 28 October 2026; the world is WorkFixture's.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 15:00:00'));
    $this->w = W::make();

    $task = W::task($this->w->nagarik, 'Retry the OTP on slow networks');
    $secret = W::task($this->w->secret, 'Hidden plan');
    W::status($task, $this->w->suman, 'done', '2026-10-28 10:00:00');
    W::status($secret, $this->w->suman, 'done', '2026-10-28 11:00:00');
    W::commit($this->w->nagarikRepo, $this->w->suman, 'a1', '2026-10-27 09:00:00');
    W::commit($this->w->secretRepo, $this->w->suman, 'a2', '2026-10-28 09:00:00');
    W::pullRequest($this->w->nagarikRepo, 3, 'suman', '2026-10-28 12:00:00');
    W::assign(W::task($this->w->nagarik, 'Bid opening report export', 'in_progress', '2026-10-26'), $this->w->suman);
});

it("answers today's work for an organization", function () {
    Sanctum::actingAs($this->w->director);

    $response = $this->getJson("/api/v1/workspace/organizations/{$this->w->sdoc->id}/work/day")->assertOk();

    expect($response->json('filters'))->toBe(['organization' => $this->w->sdoc->id, 'date' => '2026-10-28', 'under' => null])
        ->and($response->json('data.summary'))->toBe([
            'people' => 2,
            'tasks_closed' => 2,
            'tasks_started' => 0,
            'commits' => 1,
            'committers' => 1,
            'pull_requests_merged' => 1,
            'nothing_recorded' => 1,
        ])
        ->and($response->json('data.people.1.member.name'))->toBe('Suman Karki')
        ->and($response->json('data.people.1.attendance'))->toBeNull()
        ->and($response->json('data.people.1.closed.0.title'))->toBe('Retry the OTP on slow networks')
        ->and($response->json('data.people.1.hidden_closed'))->toBe(1)
        ->and($response->json('data.people.1.commits.hidden_commits'))->toBe(1);
    $response->assertDontSee('Hidden plan')->assertDontSee('secret-api');

    $other = $this->getJson("/api/v1/workspace/organizations/{$this->w->sdoc->id}/work/day?date=2026-10-27")->assertOk();

    expect($other->json('data.people.1.commits.repositories'))->toBe([['name' => 'nagarik', 'count' => 1]]);
});

it("answers a team's week, narrowed to someone's team", function () {
    Sanctum::actingAs($this->w->ed);
    $director = W::member($this->w->director);

    $response = $this->getJson("/api/v1/workspace/organizations/{$this->w->sdoc->id}/work/team?from=2026-10-26&to=2026-10-28&under={$director->id}")->assertOk();

    expect(collect($response->json('data.people'))->pluck('member.name')->all())->toBe(['Anil Gurung', 'Dipak Director', 'Suman Karki'])
        ->and($response->json('data.people.2'))->toMatchArray([
            'tasks_closed' => 2,
            'overdue' => 1,
            'commits' => 2,
            'pull_requests_merged' => 1,
        ])
        ->and($response->json('data.under'))->toBe(['id' => $director->id, 'name' => 'Dipak Director'])
        ->and($response->json('filters'))->toBe(['organization' => $this->w->sdoc->id, 'from' => '2026-10-26', 'to' => '2026-10-28', 'under' => $director->id]);
});

it("answers a person's week to them and to whoever is above them", function () {
    $suman = W::member($this->w->suman);

    Sanctum::actingAs($this->w->suman);
    $response = $this->getJson("/api/v1/workspace/members/{$suman->id}/work")->assertOk();

    expect($response->json('data.member.reports_to.name'))->toBe('Dipak Director')
        ->and($response->json('data.range'))->toBe(['from' => '2026-10-26', 'to' => '2026-10-28'])
        ->and($response->json('data.tasks.counts.closed'))->toBe(2)
        ->and(collect($response->json('data.tasks.closed'))->pluck('title')->all())->toBe(['Hidden plan', 'Retry the OTP on slow networks'])
        ->and(collect($response->json('data.tasks.overdue'))->pluck('title')->all())->toBe(['Bid opening report export'])
        ->and($response->json('data.code.count'))->toBe(2)
        ->and($response->json('data.pull_requests_merged.0.number'))->toBe(3);

    Sanctum::actingAs($this->w->director);
    $this->getJson("/api/v1/workspace/members/{$suman->id}/work")->assertOk()->assertJsonPath('data.tasks.counts.hidden_closed', 1);
});

it('refuses a peer, someone below, another organization and a team outside the view', function () {
    $suman = W::member($this->w->suman);
    $sdoc = $this->w->sdoc->id;

    Sanctum::actingAs($this->w->peer);
    $this->getJson("/api/v1/workspace/members/{$suman->id}/work")->assertForbidden();
    $this->getJson("/api/v1/workspace/organizations/{$sdoc}/work/day")->assertForbidden();

    Sanctum::actingAs($this->w->suman);
    $this->getJson('/api/v1/workspace/members/'.W::member($this->w->director)->id.'/work')->assertForbidden();
    $this->getJson("/api/v1/workspace/organizations/{$sdoc}/work/team")->assertForbidden();

    Sanctum::actingAs($this->w->mofAdmin);
    $this->getJson("/api/v1/workspace/organizations/{$sdoc}/work/day")->assertForbidden();
    $this->getJson("/api/v1/workspace/members/{$suman->id}/work")->assertForbidden();

    Sanctum::actingAs($this->w->director);
    $this->getJson("/api/v1/workspace/organizations/{$sdoc}/work/day?under=".W::member($this->w->peer)->id)->assertForbidden();
    $this->getJson("/api/v1/workspace/organizations/{$this->w->mof->id}/work/day")->assertForbidden();
});

it('lets a super-admin read any organization, and 404s one that does not exist', function () {
    Sanctum::actingAs($this->w->boss);

    $this->getJson("/api/v1/workspace/organizations/{$this->w->mof->id}/work/day")->assertOk()->assertJsonPath('data.people.0.member.name', 'Mohan Admin');
    $this->getJson('/api/v1/workspace/organizations/999999/work/day')->assertNotFound();
});

it('refuses a deactivated person with a 401', function () {
    W::member($this->w->director)->update(['is_active' => false]);
    Sanctum::actingAs($this->w->director);

    $this->getJson("/api/v1/workspace/organizations/{$this->w->sdoc->id}/work/day")->assertUnauthorized();
});

it('validates the range and the date', function () {
    Sanctum::actingAs($this->w->admin);
    $sdoc = $this->w->sdoc->id;

    $this->getJson("/api/v1/workspace/organizations/{$sdoc}/work/team?from=2026-10-28&to=2026-10-01")->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson("/api/v1/workspace/organizations/{$sdoc}/work/team?from=2026-01-01&to=2026-10-28")->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson("/api/v1/workspace/organizations/{$sdoc}/work/day?date=yesterday")->assertUnprocessable()->assertJsonValidationErrors('date');
});
