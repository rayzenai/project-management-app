<?php

use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Queries\Attendance\AttendanceSummaryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * AttendanceSummaryQuery::forMembers: attendance over a range, per person,
 * as the work reports' attendance columns read it. The week of Monday 2
 * November 2026 (winter hours 10:00 to 16:00, late after 10:15), seen on
 * Friday 6 November at 12:30.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-06 12:30:00');

    $this->org = Organization::factory()->create();
    AttendanceSetting::factory()->create(['organization_id' => $this->org->id, 'enabled_at' => '2026-10-01 09:00:00']);
    // People on the staff since before the week, unless a test says otherwise.
    $this->asha = Member::factory()->create(['organization_id' => $this->org->id, 'name' => 'Asha', 'created_at' => '2026-09-01 09:00:00']);
    $this->query = app(AttendanceSummaryQuery::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function summaryPunch(Member $member, string $kind, string $at, array $attributes = []): void
{
    AttendancePunch::factory()->at($kind, $at)->create(['member_id' => $member->id, ...$attributes]);
}

function summaryWeek(AttendanceSummaryQuery $query, Member $member): array
{
    return $query->forMembers([$member->id], CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08'))[$member->id];
}

it('subtracts lunch from the time between clocking in and out', function () {
    summaryPunch($this->asha, 'in', '2026-11-02 09:50:00');
    summaryPunch($this->asha, 'lunch_start', '2026-11-02 13:00:00');
    summaryPunch($this->asha, 'lunch_end', '2026-11-02 13:45:00');
    summaryPunch($this->asha, 'out', '2026-11-02 16:10:00');

    expect(summaryWeek($this->query, $this->asha)['minutes_worked'])->toBe(380 - 45);
});

it('counts an unfinished lunch as lunch up to the clock-out', function () {
    summaryPunch($this->asha, 'in', '2026-11-02 10:00:00');
    summaryPunch($this->asha, 'lunch_start', '2026-11-02 13:00:00');
    summaryPunch($this->asha, 'out', '2026-11-02 16:00:00');

    expect(summaryWeek($this->query, $this->asha)['minutes_worked'])->toBe(180);
});

it('counts a past day without a clock-out to the scheduled end, and flags it', function () {
    summaryPunch($this->asha, 'in', '2026-11-02 10:00:00'); // winter: ends 16:00
    summaryPunch($this->asha, 'in', '2026-10-30 10:00:00'); // autumn: ends 17:00

    $week = summaryWeek($this->query, $this->asha);
    $both = $this->query->forMembers([$this->asha->id], CarbonImmutable::parse('2026-10-30'), CarbonImmutable::parse('2026-11-02'))[$this->asha->id];

    expect($week['minutes_worked'])->toBe(360)
        ->and($week['no_clock_out_days'])->toBe(1)
        ->and($both['minutes_worked'])->toBe(360 + 420)
        ->and($both['no_clock_out_days'])->toBe(2);
});

it('counts today without a clock-out up to now, and does not flag it yet', function () {
    summaryPunch($this->asha, 'in', '2026-11-06 10:00:00');
    summaryPunch($this->asha, 'lunch_start', '2026-11-06 12:00:00');

    $week = summaryWeek($this->query, $this->asha);

    expect($week['minutes_worked'])->toBe(120)
        ->and($week['no_clock_out_days'])->toBe(0);
});

it('counts expected, present, late, leave and kaaj days up to today', function () {
    summaryPunch($this->asha, 'in', '2026-11-02 09:55:00', ['place' => AttendancePunch::PLACE_OUTSIDE]);
    summaryPunch($this->asha, 'out', '2026-11-02 16:00:00', ['place' => AttendancePunch::PLACE_UNKNOWN]);
    summaryPunch($this->asha, 'in', '2026-11-03 10:20:00');
    summaryPunch($this->asha, 'out', '2026-11-03 16:00:00');
    LeaveRequest::factory()->approved()->create(['member_id' => $this->asha->id, 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);
    LeaveRequest::factory()->approved()->kaaj()->create(['member_id' => $this->asha->id, 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05']);
    LeaveRequest::factory()->create(['member_id' => $this->asha->id, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-06']); // pending: no effect

    expect(summaryWeek($this->query, $this->asha))->toBe([
        'days_expected' => 3, // Mon, Tue, Fri (Wed leave, Thu kaaj, the weekend not yet)
        'days_present' => 2,
        'days_late' => 1,
        'minutes_worked' => 365 + 340,
        'no_clock_out_days' => 0,
        'outside_punches' => 2,
        'leave_days' => 1,
        'kaaj_days' => 1,
        'usual_in' => '10:07',
    ]);
});

it('counts the minutes of a day off worked, but not the day', function () {
    CarbonImmutable::setTestNow('2026-11-09 09:00:00');
    Holiday::factory()->create(['organization_id' => $this->org->id, 'date' => '2026-11-06', 'name' => 'Tihar']);
    summaryPunch($this->asha, 'in', '2026-11-07 10:00:00');
    summaryPunch($this->asha, 'out', '2026-11-07 12:00:00');

    $week = summaryWeek($this->query, $this->asha);

    expect($week['days_expected'])->toBe(4) // Mon to Thu; Fri is a holiday
        ->and($week['days_present'])->toBe(0)
        ->and($week['minutes_worked'])->toBe(120)
        ->and($week['usual_in'])->toBe('10:00');
});

it('takes the median clock-in as the usual time', function () {
    summaryPunch($this->asha, 'in', '2026-11-02 09:50:00');
    summaryPunch($this->asha, 'in', '2026-11-03 10:20:00');
    summaryPunch($this->asha, 'in', '2026-11-04 10:00:00');

    expect(summaryWeek($this->query, $this->asha)['usual_in'])->toBe('10:00');
});

it('expects nothing of an exempt person, and nothing before attendance was turned on', function () {
    $exempt = Member::factory()->create(['organization_id' => $this->org->id, 'attendance_exempt' => true, 'created_at' => '2026-09-01 09:00:00']);
    AttendanceSetting::query()->where('organization_id', $this->org->id)->update(['enabled_at' => '2026-11-04 15:00:00']);

    $rows = $this->query->forMembers([$exempt->id, $this->asha->id], CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08'));

    expect($rows[$exempt->id]['days_expected'])->toBe(0)
        ->and($rows[$this->asha->id]['days_expected'])->toBe(3); // Wed, Thu, Fri
});

it('expects nothing while attendance is off', function () {
    AttendanceSetting::query()->where('organization_id', $this->org->id)->update(['enabled' => false]);
    summaryPunch($this->asha, 'in', '2026-11-02 10:00:00');
    summaryPunch($this->asha, 'out', '2026-11-02 16:00:00');

    $week = summaryWeek($this->query, $this->asha);

    expect($week['days_expected'])->toBe(0)
        ->and($week['days_present'])->toBe(0)
        ->and($week['minutes_worked'])->toBe(360);
});

it('returns a row of zeros for people with nothing, and nothing for nobody', function () {
    $rows = $this->query->forMembers([$this->asha->id, 999999], CarbonImmutable::parse('2026-11-07'), CarbonImmutable::parse('2026-11-08'));

    expect($rows)->toHaveKeys([$this->asha->id, 999999])
        ->and($rows[999999])->toBe($rows[$this->asha->id])
        ->and($rows[999999]['usual_in'])->toBeNull()
        ->and($this->query->forMembers([], CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08')))->toBe([]);
});

it('reads the same few queries for one person or many, over any number of days', function () {
    $people = Member::factory()->count(6)->create(['organization_id' => $this->org->id, 'created_at' => '2026-09-01 09:00:00']);

    foreach ($people as $person) {
        summaryPunch($person, 'in', '2026-11-02 10:00:00');
        LeaveRequest::factory()->approved()->create(['member_id' => $person->id, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03']);
    }

    $count = function (array $ids, string $from): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->query->forMembers($ids, CarbonImmutable::parse($from), CarbonImmutable::parse('2026-11-06'));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $one = $count([$people->first()->id], '2026-11-02');

    expect($count($people->modelKeys(), '2026-08-10'))->toBe($one)
        ->and($one)->toBeLessThanOrEqual(5);
});

it('expects nobody before the day they were added', function () {
    $newcomer = Member::factory()->create(['organization_id' => $this->org->id, 'created_at' => '2026-11-04 11:00:00']);

    expect(summaryWeek($this->query, $newcomer)['days_expected'])->toBe(3);
});
