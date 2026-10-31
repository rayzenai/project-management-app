<?php

use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * The JSON twins of the register and the attendance totals, through
 * Sanctum. Monday 2 November 2026 at 11:00 (winter hours, late after
 * 10:15). SDOC: a director with two people below, a peer, an admin; MoF has
 * its own admin.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-11-02 11:00:00');

    $this->sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $this->mof = Organization::factory()->create(['name' => 'MoF']);
    AttendanceSetting::factory()->create(['organization_id' => $this->sdoc->id, 'enabled_at' => '2026-10-01 09:00:00']);
    AttendanceSetting::factory()->create(['organization_id' => $this->mof->id, 'enabled_at' => '2026-10-01 09:00:00']);

    $person = function (Organization $organization, string $name, array $attributes = []): Member {
        $user = User::factory()->create(['name' => $name]);

        return Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'created_at' => '2026-09-01 09:00:00', ...$attributes]);
    };

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = $person($this->sdoc, 'Admin', ['is_office_admin' => true]);
    $this->mofAdmin = $person($this->mof, 'MoF Admin', ['is_office_admin' => true]);
    $this->director = $person($this->sdoc, 'Director');
    $this->peer = $person($this->sdoc, 'Peer');
    $this->anil = $person($this->sdoc, 'Anil', ['reports_to_id' => $this->director->id]);
    $this->bikash = $person($this->sdoc, 'Bikash', ['reports_to_id' => $this->director->id]);

    AttendancePunch::factory()->at('in', '2026-11-02 10:20:00')->create(['member_id' => $this->anil->id]);
    AttendancePunch::factory()->at('in', '2026-10-30 09:50:00')->create(['member_id' => $this->anil->id]);
    AttendancePunch::factory()->at('out', '2026-10-30 17:20:00')->create(['member_id' => $this->anil->id]);
    LeaveRequest::factory()->approved()->kaaj('Pokhara')->create(['member_id' => $this->bikash->id, 'starts_on' => '2026-10-29', 'ends_on' => '2026-11-02']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('returns the day\'s register to a director, for the people below them', function () {
    Sanctum::actingAs($this->director->user, ['*']);

    $response = $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance")->assertOk();

    expect($response->json('data.summary'))->toBe([
        'staff' => 2, 'on_leave' => 0, 'on_kaaj' => 1, 'expected' => 1, 'present' => 1,
        'late' => 1, 'not_in' => 0, 'outside_office' => 0, 'percentage' => 100,
    ])
        ->and($response->json('data.schedule.end'))->toBe('16:00')
        ->and($response->json('data.people.0.member.name'))->toBe('Anil')
        ->and($response->json('data.people.0.state'))->toBe('in')
        ->and($response->json('data.people.1.leave.place'))->toBe('Pokhara')
        ->and($response->json('filters'))->toBe(['date' => '2026-11-02', 'under' => null, 'organization' => $this->sdoc->id]);
});

it('takes a date and someone to narrow to', function () {
    Sanctum::actingAs($this->admin->user, ['*']);

    $response = $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance?date=2026-10-30&under={$this->anil->id}")->assertOk();

    expect($response->json('data.people'))->toHaveCount(1)
        ->and($response->json('data.people.0.out_at'))->toBe(CarbonImmutable::parse('2026-10-30 17:20:00')->toIso8601String())
        ->and($response->json('data.schedule.end'))->toBe('17:00');
});

it('refuses another organization, a peer, `under` someone not below, and attendance off', function () {
    Sanctum::actingAs($this->mofAdmin->user, ['*']);
    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance")->assertForbidden();

    Sanctum::actingAs($this->peer->user, ['*']);
    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance")->assertForbidden();

    Sanctum::actingAs($this->director->user, ['*']);
    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance?under={$this->peer->id}")->assertForbidden();
    $this->getJson("/api/v1/workspace/organizations/{$this->mof->id}/attendance")->assertForbidden();

    AttendanceSetting::query()->where('organization_id', $this->sdoc->id)->update(['enabled' => false]);
    Sanctum::actingAs($this->boss, ['*']);
    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance")->assertForbidden();
});

it('answers a deactivated director 401', function () {
    $this->director->update(['is_active' => false]);
    Sanctum::actingAs($this->director->user, ['*']);

    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance")->assertUnauthorized();
});

it('totals attendance over a range for the people in view', function () {
    Sanctum::actingAs($this->director->user, ['*']);

    $response = $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance/summary?from=2026-10-26&to=2026-11-02")->assertOk();
    $rows = collect($response->json('data'))->keyBy('member.name');

    expect($rows->keys()->all())->toBe(['Anil', 'Bikash'])
        ->and($rows['Anil'])->toMatchArray([
            'days_expected' => 6, // Mon 26 Oct to Mon 2 Nov, six working days
            'days_present' => 2,
            'days_late' => 1,
            'minutes_worked' => 450 + 40, // 30 Oct in full; today from 10:20 to 11:00
            'usual_in' => '10:05',
        ])
        ->and($rows['Bikash'])->toMatchArray(['days_expected' => 3, 'kaaj_days' => 3, 'days_present' => 0]) // kaaj Thu, Fri, Mon
        ->and($response->json('filters'))->toBe(['from' => '2026-10-26', 'to' => '2026-11-02', 'under' => null, 'organization' => $this->sdoc->id]);
});

it('defaults the totals to the last seven days and refuses more than 92', function () {
    Sanctum::actingAs($this->admin->user, ['*']);

    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance/summary")
        ->assertOk()
        ->assertJsonPath('filters.from', '2026-10-27')
        ->assertJsonPath('filters.to', '2026-11-02');

    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance/summary?from=2026-08-01&to=2026-11-02")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');

    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance/summary?from=2026-11-02&to=2026-11-01")
        ->assertUnprocessable();
});

it('refuses the totals to whoever may not see the register', function () {
    Sanctum::actingAs($this->peer->user, ['*']);
    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance/summary")->assertForbidden();

    Sanctum::actingAs($this->mofAdmin->user, ['*']);
    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance/summary")->assertForbidden();

    Sanctum::actingAs($this->director->user, ['*']);
    $this->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/attendance/summary?under={$this->admin->id}")->assertForbidden();
});
