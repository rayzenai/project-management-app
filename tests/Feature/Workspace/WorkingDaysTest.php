<?php

use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\WorkingDays;
use Carbon\CarbonImmutable;

/*
 * Working days: Sunday to Friday without attendance, the attendance
 * calendar (workdays and holidays) with it. 2026-11-06 is a Friday.
 */

function workingDaysDate(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

it('works Sunday to Friday without attendance', function () {
    $days = WorkingDays::forOrganization(null);

    expect($days->isWorkingDay(workingDaysDate('2026-11-01')))->toBeTrue()  // Sunday
        ->and($days->isWorkingDay(workingDaysDate('2026-11-06')))->toBeTrue()  // Friday
        ->and($days->isWorkingDay(workingDaysDate('2026-11-07')))->toBeFalse() // Saturday
        ->and($days->nextWorkingDay(workingDaysDate('2026-11-06'))->toDateString())->toBe('2026-11-08')
        ->and($days->weekStart(workingDaysDate('2026-11-04'))->toDateString())->toBe('2026-11-01')
        ->and($days->weekStart(workingDaysDate('2026-11-07'))->toDateString())->toBe('2026-11-01');
});

it('ignores the Monday to Friday default while attendance is off', function () {
    $org = Organization::factory()->create();
    AttendanceSetting::factory()->off()->create(['organization_id' => $org->id]);

    expect(WorkingDays::forOrganization($org->id)->isWorkingDay(workingDaysDate('2026-11-01')))->toBeTrue();
});

it('follows the attendance calendar when attendance is on', function () {
    $org = Organization::factory()->create();
    AttendanceSetting::factory()->create(['organization_id' => $org->id, 'workdays' => [1, 2, 3, 4, 5]]);
    Holiday::factory()->create(['organization_id' => $org->id, 'date' => '2026-11-09', 'name' => 'Holiday']);

    $days = WorkingDays::forOrganization($org->id);

    expect($days->isWorkingDay(workingDaysDate('2026-11-01')))->toBeFalse()
        ->and($days->isWorkingDay(workingDaysDate('2026-11-09')))->toBeFalse()
        ->and($days->nextWorkingDay(workingDaysDate('2026-11-06'))->toDateString())->toBe('2026-11-10')
        ->and($days->weekStart(workingDaysDate('2026-11-04'))->toDateString())->toBe('2026-11-02');
});

it('gives up after 31 days and returns the next day', function () {
    $org = Organization::factory()->create();
    AttendanceSetting::factory()->create(['organization_id' => $org->id, 'workdays' => []]);

    expect(WorkingDays::forOrganization($org->id)->nextWorkingDay(workingDaysDate('2026-11-06'))->toDateString())->toBe('2026-11-07');
});

it('reads the organization of the user\'s member', function () {
    $org = Organization::factory()->create();
    AttendanceSetting::factory()->create(['organization_id' => $org->id, 'workdays' => [1, 2, 3, 4, 5]]);
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);

    expect(WorkingDays::forUser($user)->isWorkingDay(workingDaysDate('2026-11-01')))->toBeFalse();
});

it('falls back to Sunday to Friday for a user with no member or no organization', function () {
    $loner = User::factory()->create();
    $orphan = User::factory()->create();
    Member::factory()->create(['user_id' => $orphan->id, 'organization_id' => null]);

    foreach ([null, $loner, $orphan] as $user) {
        expect(WorkingDays::forUser($user)->isWorkingDay(workingDaysDate('2026-11-01')))->toBeTrue();
    }
});

it('keeps the week start on a holiday Sunday', function () {
    $org = Organization::factory()->create();
    AttendanceSetting::factory()->create(['organization_id' => $org->id, 'workdays' => [7, 1, 2, 3, 4, 5]]);
    Holiday::factory()->create(['organization_id' => $org->id, 'date' => '2026-11-01', 'name' => 'Holiday']);

    $days = WorkingDays::forOrganization($org->id);

    expect($days->isWorkingDay(workingDaysDate('2026-11-01')))->toBeFalse()
        ->and($days->weekStart(workingDaysDate('2026-11-04'))->toDateString())->toBe('2026-11-01')
        ->and($days->weekStart(workingDaysDate('2026-11-01'))->toDateString())->toBe('2026-11-01');
});
