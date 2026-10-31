<?php

use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Queries\Attendance\AttendanceRegisterQuery;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * The attendance register (/workspace/attendance): who should be in, who
 * is, and who sees it. Monday 2 November 2026 at 11:00, the first day of
 * winter hours (10:00 to 16:00, late after 10:15). SDOC: the ED; a
 * director with people below her (one exempt, one deactivated, an intern
 * one level further down); a peer of the director with nobody below; an
 * admin. MoF is another organization with attendance on.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-11-02 11:00:00');

    $this->sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $this->mof = Organization::factory()->create(['name' => 'MoF']);
    AttendanceSetting::factory()->create(['organization_id' => $this->sdoc->id, 'enabled_at' => '2026-10-01 09:00:00']);
    AttendanceSetting::factory()->create(['organization_id' => $this->mof->id, 'enabled_at' => '2026-10-01 09:00:00']);

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = registerPerson($this->sdoc, 'Admin', ['is_office_admin' => true]);
    $this->mofAdmin = registerPerson($this->mof, 'MoF Admin', ['is_office_admin' => true]);
    $this->ed = registerPerson($this->sdoc, 'Executive Director');
    $this->director = registerPerson($this->sdoc, 'Director', ['reports_to_id' => $this->ed->id]);
    $this->peer = registerPerson($this->sdoc, 'Peer', ['reports_to_id' => $this->ed->id]);

    $below = fn (string $name, array $attributes = []) => registerPerson($this->sdoc, $name, ['reports_to_id' => $this->director->id, ...$attributes]);
    $this->anil = $below('Anil');       // in 09:41, office network
    $this->bikash = $below('Bikash');   // in 10:21, late, near office
    $this->chandra = $below('Chandra'); // in, at lunch
    $this->deepak = $below('Deepak');   // in and out
    $this->suman = $below('Suman');     // in 10:34, late, outside office
    $this->asmita = $below('Asmita');   // not in
    $this->prakash = $below('Prakash'); // on leave
    $this->rohan = $below('Rohan');     // on kaaj
    $this->yam = $below('Yam');         // only a pending request: not in
    $this->exempt = $below('Exempt', ['attendance_exempt' => true]);
    $this->gone = $below('Gone', ['is_active' => false]);
    $this->intern = registerPerson($this->sdoc, 'Intern', ['reports_to_id' => $this->anil->id]); // not in

    registerPunch($this->anil, 'in', '2026-11-02 09:41:00');
    registerPunch($this->bikash, 'in', '2026-11-02 10:21:00', ['place' => AttendancePunch::PLACE_NEAR_OFFICE, 'distance_m' => 30]);
    registerPunch($this->chandra, 'in', '2026-11-02 09:55:00');
    registerPunch($this->chandra, 'lunch_start', '2026-11-02 10:50:00');
    registerPunch($this->deepak, 'in', '2026-11-02 09:30:00');
    registerPunch($this->deepak, 'out', '2026-11-02 10:45:00');
    registerPunch($this->suman, 'in', '2026-11-02 10:34:00', ['place' => AttendancePunch::PLACE_OUTSIDE, 'distance_m' => 3400, 'note' => 'Meeting at Singha Durbar']);
    registerPunch($this->exempt, 'in', '2026-11-02 09:00:00');
    registerPunch($this->anil, 'in', '2026-11-01 09:00:00'); // another day

    LeaveRequest::factory()->approved()->create(['member_id' => $this->prakash->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'reason' => 'Sick']);
    LeaveRequest::factory()->approved()->kaaj('Pokhara')->create(['member_id' => $this->rohan->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-04']);
    LeaveRequest::factory()->create(['member_id' => $this->yam->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function registerPerson(Organization $organization, string $name, array $attributes = []): Member
{
    $user = User::factory()->create(['name' => $name]);

    return Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, ...$attributes]);
}

function registerPunch(Member $member, string $kind, string $at, array $attributes = []): AttendancePunch
{
    return AttendancePunch::factory()->at($kind, $at)->create(['member_id' => $member->id, ...$attributes]);
}

/**
 * @return array<string, mixed>
 */
function registerPage(TestResponse $response): array
{
    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Attendance/Register'));

    return $response->viewData('page')['props'];
}

/**
 * @param  array<string, mixed>  $register
 * @return array<string, array<string, mixed>> people by name
 */
function registerRows(array $register): array
{
    return collect($register['people'])->keyBy('member.name')->all();
}

/**
 * @param  array<string, mixed>  $register
 * @return array<string, string> state by name
 */
function registerStates(array $register): array
{
    return collect(registerRows($register))->map(fn (array $row): string => $row['state'])->all();
}

it('counts who should be in and gives each person their state', function () {
    $register = registerPage($this->actingAs($this->director->user)->get('/workspace/attendance'));

    expect($register['date'])->toBe('2026-11-02')
        ->and($register['summary'])->toBe([
            'staff' => 10,
            'on_leave' => 1,
            'on_kaaj' => 1,
            'expected' => 8,
            'present' => 5,
            'late' => 2,
            'not_in' => 3,
            'outside_office' => 1,
            'percentage' => 63,
        ])
        ->and(registerStates($register))->toEqual([
            'Anil' => 'in',
            'Asmita' => 'not_in',
            'Bikash' => 'in',
            'Chandra' => 'at_lunch',
            'Deepak' => 'out',
            'Intern' => 'not_in',
            'Prakash' => 'on_leave',
            'Rohan' => 'on_kaaj',
            'Suman' => 'in',
            'Yam' => 'not_in',
        ]);
});

it('gives each clock-in its time, lateness, place and note, and each leave its days', function () {
    $rows = registerRows(registerPage($this->actingAs($this->director->user)->get('/workspace/attendance')));

    expect($rows['Anil'])->toMatchArray(['late' => false, 'place' => 'office_network', 'note' => null])
        ->and($rows['Anil']['in_at'])->toBe(CarbonImmutable::parse('2026-11-02 09:41:00')->toIso8601String())
        ->and($rows['Bikash'])->toMatchArray(['late' => true, 'place' => 'near_office', 'distance_m' => 30])
        ->and($rows['Suman'])->toMatchArray(['late' => true, 'place' => 'outside', 'distance_m' => 3400, 'note' => 'Meeting at Singha Durbar'])
        ->and($rows['Chandra']['lunch_start_at'])->not->toBeNull()
        ->and($rows['Chandra']['out_at'])->toBeNull()
        ->and($rows['Deepak']['out_at'])->toBe(CarbonImmutable::parse('2026-11-02 10:45:00')->toIso8601String())
        ->and($rows['Asmita'])->toMatchArray(['in_at' => null, 'late' => false, 'place' => null, 'leave' => null])
        ->and($rows['Rohan']['leave'])->toBe(['kind' => 'kaaj', 'place' => 'Pokhara', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-04'])
        ->and($rows['Prakash']['leave']['kind'])->toBe('leave')
        ->and($rows['Yam']['leave'])->toBeNull();
});

it('uses winter hours from 2 November', function () {
    $winter = registerPage($this->actingAs($this->admin->user)->get('/workspace/attendance'));
    $autumn = registerPage($this->actingAs($this->admin->user)->get('/workspace/attendance?date=2026-10-30'));

    expect($winter['schedule'])->toMatchArray(['start' => '10:00', 'end' => '16:00', 'late_after' => '10:15', 'expects_work' => true])
        ->and($autumn['schedule'])->toMatchArray(['start' => '10:00', 'end' => '17:00', 'expects_work' => true]);
});

it('expects nobody on a Saturday: everyone not punched is off', function () {
    registerPunch($this->anil, 'in', '2026-11-07 11:30:00');
    LeaveRequest::factory()->approved()->create(['member_id' => $this->asmita->id, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-09']);

    $register = registerPage($this->actingAs($this->director->user)->get('/workspace/attendance?date=2026-11-07'));
    $states = registerStates($register);

    expect($register['schedule']['expects_work'])->toBeFalse()
        ->and($register['summary'])->toMatchArray(['staff' => 10, 'expected' => 0, 'present' => 1, 'late' => 0, 'not_in' => 0, 'on_leave' => 0, 'on_kaaj' => 0, 'percentage' => null])
        ->and($states['Anil'])->toBe('in')
        ->and(registerRows($register)['Anil']['late'])->toBeFalse()
        ->and($states['Asmita'])->toBe('off')
        ->and(collect($states)->except('Anil')->unique()->values()->all())->toBe(['off']);
});

it('expects nobody on a holiday', function () {
    Holiday::factory()->create(['organization_id' => $this->sdoc->id, 'date' => '2026-11-09', 'name' => 'Tihar']);

    $register = registerPage($this->actingAs($this->director->user)->get('/workspace/attendance?date=2026-11-09'));

    expect($register['schedule'])->toMatchArray(['holiday' => 'Tihar', 'expects_work' => false])
        ->and($register['summary'])->toMatchArray(['expected' => 0, 'present' => 0, 'percentage' => null])
        ->and(collect(registerStates($register))->unique()->values()->all())->toBe(['off']);
});

it('leaves attendance-exempt and deactivated people out', function () {
    $names = array_keys(registerRows(registerPage($this->actingAs($this->admin->user)->get('/workspace/attendance'))));

    expect($names)->not->toContain('Exempt')
        ->and($names)->not->toContain('Gone')
        ->and($names)->toContain('Executive Director', 'Admin', 'Peer', 'Director', 'Intern');
});

it('shows an admin everyone in the organization and a director only the people below them', function () {
    $admin = registerPage($this->actingAs($this->admin->user)->get('/workspace/attendance'));
    $director = registerPage($this->actingAs($this->director->user)->get('/workspace/attendance'));
    $boss = registerPage($this->actingAs($this->boss)->get('/workspace/attendance?organization='.$this->sdoc->id));

    expect($admin['summary']['staff'])->toBe(14)
        ->and($boss['summary']['staff'])->toBe(14)
        ->and($director['summary']['staff'])->toBe(10)
        ->and(array_keys(registerRows($director)))->not->toContain('Peer', 'Executive Director', 'Director')
        ->and($admin['filters'])->toBe(['date' => '2026-11-02', 'organization' => $this->sdoc->id, 'under' => null]);
});

it('offers the teams of people in view as the person filter', function () {
    $admin = registerPage($this->actingAs($this->admin->user)->get('/workspace/attendance'));
    $director = registerPage($this->actingAs($this->director->user)->get('/workspace/attendance'));

    expect(collect($admin['options']['teams'])->pluck('name')->all())->toBe(['Anil', 'Director', 'Executive Director'])
        ->and(collect($director['options']['teams'])->pluck('name')->all())->toBe(['Anil']);
});

it('narrows to someone and everyone below them', function () {
    $register = registerPage($this->actingAs($this->director->user)->get('/workspace/attendance?under='.$this->anil->id));

    expect(array_keys(registerRows($register)))->toEqualCanonicalizing(['Anil', 'Intern'])
        ->and($register['filters']['under'])->toBe($this->anil->id)
        ->and($register['options']['teams'])->toHaveCount(1);
});

it('refuses `under` someone not below the viewer, in another organization, or unknown', function () {
    $outsider = registerPerson($this->mof, 'Outsider');

    $this->actingAs($this->director->user)->get('/workspace/attendance?under='.$this->peer->id)->assertForbidden();
    $this->actingAs($this->director->user)->get('/workspace/attendance?under='.$this->ed->id)->assertForbidden();
    $this->actingAs($this->anil->user)->get('/workspace/attendance?under='.$this->bikash->id)->assertForbidden();
    $this->actingAs($this->admin->user)->get('/workspace/attendance?under='.$outsider->id)->assertForbidden();
    $this->actingAs($this->boss)->get('/workspace/attendance?organization='.$this->sdoc->id.'&under='.$outsider->id)->assertForbidden();
    $this->actingAs($this->admin->user)->get('/workspace/attendance?under=999999')->assertForbidden();
});

it('refuses people with nobody below them, other organizations and outsiders', function () {
    $this->actingAs($this->peer->user)->get('/workspace/attendance')->assertForbidden();
    $this->actingAs($this->intern->user)->get('/workspace/attendance')->assertForbidden();
    $this->actingAs($this->mofAdmin->user)->get('/workspace/attendance?organization='.$this->sdoc->id)->assertForbidden();
    $this->actingAs($this->director->user)->get('/workspace/attendance?organization='.$this->mof->id)->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/workspace/attendance')->assertForbidden();
});

it('refuses a deactivated director', function () {
    $this->director->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canViewAttendanceRegister($this->director->user, $this->sdoc->id))->toBeFalse();

    $this->actingAs($this->director->user)->get('/workspace/attendance')->assertRedirect();
});

it('refuses everyone while the organization has attendance off', function () {
    AttendanceSetting::query()->where('organization_id', $this->sdoc->id)->update(['enabled' => false]);

    $this->actingAs($this->director->user)->get('/workspace/attendance')->assertForbidden();
    $this->actingAs($this->admin->user)->get('/workspace/attendance')->assertForbidden();
    $this->actingAs($this->boss)->get('/workspace/attendance?organization='.$this->sdoc->id)->assertForbidden();
});

it('opens a super-admin on the first organization with attendance on, and lists those organizations', function () {
    $register = registerPage($this->actingAs($this->boss)->get('/workspace/attendance'));

    expect($register['filters']['organization'])->toBe($this->mof->id)
        ->and($register['options']['organizations'])->toBe([
            ['id' => $this->mof->id, 'name' => 'MoF'],
            ['id' => $this->sdoc->id, 'name' => 'SDOC'],
        ]);

    $director = registerPage($this->actingAs($this->director->user)->get('/workspace/attendance'));

    expect($director['options']['organizations'])->toBe([]);
});

it('rejects a malformed date', function () {
    $this->actingAs($this->admin->user)->get('/workspace/attendance?date=2-11-2026')->assertSessionHasErrors('date');
});

it('lists nobody on a day before they were added, unless they punched that day', function () {
    $newcomer = registerPerson($this->sdoc, 'Newcomer', ['created_at' => '2026-11-03 10:00:00']);
    $early = registerPerson($this->sdoc, 'Early bird', ['created_at' => '2026-11-03 10:00:00']);
    registerPunch($early, 'in', '2026-11-02 09:50:00');

    $names = collect(app(AttendanceRegisterQuery::class)
        ->run($this->admin->user, $this->sdoc->id, CarbonImmutable::parse('2026-11-02'))['people'])
        ->pluck('member.name');

    expect($names)->not->toContain('Newcomer')->toContain('Early bird');
});
