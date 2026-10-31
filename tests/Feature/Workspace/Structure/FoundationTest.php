<?php

use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\Attendance\AttendanceCalendar;
use App\Support\OrgChart;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->org = Organization::factory()->create();
    $person = fn (array $attributes = []) => Member::factory()->linkedTo(User::factory()->create())->create(['organization_id' => $this->org->id, ...$attributes]);
    $this->person = $person;
    // ED -> director -> engineer -> intern; a peer of the director.
    $this->ed = $person();
    $this->director = $person(['reports_to_id' => $this->ed->id]);
    $this->engineer = $person(['reports_to_id' => $this->director->id]);
    $this->intern = $person(['reports_to_id' => $this->engineer->id]);
    $this->peer = $person(['reports_to_id' => $this->ed->id]);
});

it('walks reporting lines down and up any number of levels', function () {
    expect(OrgChart::descendantIds($this->director))->toEqualCanonicalizing([$this->engineer->id, $this->intern->id])
        ->and(OrgChart::descendantIds($this->ed))->toEqualCanonicalizing([$this->director->id, $this->engineer->id, $this->intern->id, $this->peer->id])
        ->and(OrgChart::descendantIds($this->intern))->toBe([])
        ->and(OrgChart::ancestorIds($this->intern))->toEqualCanonicalizing([$this->engineer->id, $this->director->id, $this->ed->id])
        ->and(OrgChart::ancestorIds($this->ed))->toBe([]);
});

it('leaves deleted people out of who is below', function () {
    $this->intern->delete();
    OrgChart::flush();

    expect(OrgChart::descendantIds($this->director))->toBe([$this->engineer->id]);
});

it('refuses a reporting line that would close a loop', function () {
    expect(OrgChart::wouldLoop($this->director, $this->intern))->toBeTrue()
        ->and(OrgChart::wouldLoop($this->director, $this->director))->toBeTrue()
        ->and(OrgChart::wouldLoop($this->director, $this->peer))->toBeFalse()
        ->and(OrgChart::wouldLoop($this->director, null))->toBeFalse();
});

it('lets everyone above a person oversee them, and nobody else', function () {
    $user = fn (Member $m) => $m->user;

    expect(WorkspaceAccess::oversees($user($this->director), $this->intern))->toBeTrue()
        ->and(WorkspaceAccess::oversees($user($this->ed), $this->intern))->toBeTrue()
        ->and(WorkspaceAccess::oversees($user($this->peer), $this->intern))->toBeFalse()
        ->and(WorkspaceAccess::oversees($user($this->intern), $this->director))->toBeFalse()
        ->and(WorkspaceAccess::oversees($user($this->director), $this->director))->toBeFalse()
        ->and(WorkspaceAccess::canViewPersonOf($user($this->director), $this->director))->toBeTrue();
});

it('gives a deactivated higher authority no oversight', function () {
    $this->director->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::oversees($this->director->user, $this->engineer))->toBeFalse()
        ->and(WorkspaceAccess::overseenMemberIds($this->director->user, $this->org->id))->toBe([]);
});

it('lets the organization admins and super-admins oversee everyone in it, and no other organization', function () {
    $admin = ($this->person)(['is_office_admin' => true]);
    $otherOrg = Organization::factory()->create();
    $outsider = Member::factory()->create(['organization_id' => $otherOrg->id]);
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    expect(WorkspaceAccess::oversees($admin->user, $this->ed))->toBeTrue()
        ->and(WorkspaceAccess::oversees($admin->user, $outsider))->toBeFalse()
        ->and(WorkspaceAccess::overseenMemberIds($admin->user, $this->org->id))->toBeNull()
        ->and(WorkspaceAccess::overseenMemberIds($admin->user, $otherOrg->id))->toBe([])
        ->and(WorkspaceAccess::oversees($boss, $outsider))->toBeTrue()
        ->and(WorkspaceAccess::overseenMemberIds($boss, $otherOrg->id))->toBeNull();
});

it('never lets a reporting line reach into another organization', function () {
    $otherOrg = Organization::factory()->create();
    $stray = Member::factory()->create(['organization_id' => $otherOrg->id, 'reports_to_id' => $this->engineer->id]);

    expect(WorkspaceAccess::oversees($this->engineer->user, $stray))->toBeFalse()
        ->and(WorkspaceAccess::overseenMemberIds($this->engineer->user, $this->org->id))->toBe([$this->intern->id]);
});

it('shows the organogram to the organization and super-admins only', function () {
    $outsider = Member::factory()->linkedTo(User::factory()->create())->create(['organization_id' => Organization::factory()->create()->id]);

    expect(WorkspaceAccess::canViewOrganogram($this->intern->user, $this->org->id))->toBeTrue()
        ->and(WorkspaceAccess::canViewOrganogram($outsider->user, $this->org->id))->toBeFalse()
        ->and(WorkspaceAccess::canManageStructure($this->ed->user, $this->org->id))->toBeFalse()
        ->and(WorkspaceAccess::canManageStructure(User::factory()->create(['email' => 'boss@example.com']), $this->org->id))->toBeTrue();
});

it('lets people punch only once a super-admin turned attendance on', function () {
    expect(WorkspaceAccess::canPunch($this->intern->user))->toBeFalse()
        ->and(WorkspaceAccess::canViewAttendanceRegister($this->director->user, $this->org->id))->toBeFalse();

    AttendanceSetting::factory()->create(['organization_id' => $this->org->id]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canPunch($this->intern->user))->toBeTrue()
        ->and(WorkspaceAccess::canViewAttendanceRegister($this->director->user, $this->org->id))->toBeTrue()
        ->and(WorkspaceAccess::canViewAttendanceRegister($this->intern->user, $this->org->id))->toBeFalse()
        ->and(WorkspaceAccess::canEnableAttendance($this->ed->user))->toBeFalse();
});

it('lets whoever oversees a person decide their leave, never themselves', function () {
    $leave = LeaveRequest::factory()->create(['member_id' => $this->engineer->id]);

    expect(WorkspaceAccess::canDecideLeave($this->director->user, $leave))->toBeTrue()
        ->and(WorkspaceAccess::canDecideLeave($this->engineer->user, $leave))->toBeFalse()
        ->and(WorkspaceAccess::canDecideLeave($this->intern->user, $leave))->toBeFalse();
});

it('knows working days, holidays and winter hours', function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->org->id]);
    Holiday::factory()->create(['organization_id' => $this->org->id, 'date' => '2026-11-09', 'name' => 'Tihar']);
    $calendar = AttendanceCalendar::for($this->org);

    $winter = $calendar->day(CarbonImmutable::parse('2026-11-02'));
    $autumn = $calendar->day(CarbonImmutable::parse('2026-10-30'));

    expect($winter->expectsWork())->toBeTrue()
        ->and($winter->start->format('H:i'))->toBe('10:00')
        ->and($winter->end->format('H:i'))->toBe('16:00')
        ->and($winter->lateAfter->format('H:i'))->toBe('10:15')
        ->and($autumn->end->format('H:i'))->toBe('17:00')
        ->and($calendar->day(CarbonImmutable::parse('2027-01-29'))->end->format('H:i'))->toBe('16:00')
        ->and($calendar->day(CarbonImmutable::parse('2027-02-01'))->end->format('H:i'))->toBe('17:00')
        ->and($calendar->day(CarbonImmutable::parse('2026-11-07'))->expectsWork())->toBeFalse()
        ->and($calendar->day(CarbonImmutable::parse('2026-11-09'))->holiday)->toBe('Tihar')
        ->and($calendar->day(CarbonImmutable::parse('2026-11-09'))->expectsWork())->toBeFalse()
        ->and($calendar->days(CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08')))->toHaveCount(7);
});
