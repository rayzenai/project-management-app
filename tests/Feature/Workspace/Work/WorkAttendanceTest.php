<?php

use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Workspace\Work\WorkFixture as W;

/*
 * The work reports show attendance beside the work once the organization
 * has it on: the day's state on today's work, the range's totals on a
 * team's week and a person's week. Today is Wednesday 28 October 2026
 * (10:00 to 17:00, late after 10:15).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 15:00:00'));
    $this->w = W::make();
});

/** @return array<string, mixed> */
function attendanceWorkPage(TestResponse $response, string $component): array
{
    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component($component));

    return $response->viewData('page')['props'];
}

/**
 * @param  array<string, mixed>  $page
 * @return array<string, array<string, mixed>> rows by person name
 */
function attendanceWorkRows(array $page): array
{
    return collect($page['people'])->keyBy(fn (array $row): string => $row['member']['name'])->all();
}

it('shows no attendance while the organization has it off', function () {
    $page = attendanceWorkPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day');

    expect(collect($page['people'])->pluck('attendance')->filter()->all())->toBe([]);
});

it("shows each person's day beside today's work once attendance is on", function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->w->sdoc->id]);
    AttendancePunch::factory()->at(AttendancePunch::IN, '2026-10-28 10:34:00')->create([
        'member_id' => W::member($this->w->suman)->id,
        'place' => AttendancePunch::PLACE_OUTSIDE,
        'note' => 'Meeting at Singha Durbar',
    ]);
    LeaveRequest::factory()->approved()->kaaj()->create(['member_id' => W::member($this->w->anil)->id]);

    $rows = attendanceWorkRows(attendanceWorkPage($this->actingAs($this->w->admin)->get('/workspace/work'), 'Work/Day'));

    expect($rows['Suman Karki']['attendance'])->toMatchArray(['state' => 'in', 'late' => true, 'place' => 'outside'])
        ->and($rows[W::member($this->w->anil)->name]['attendance']['state'])->toBe('on_kaaj');
});

it("adds attendance totals to a team's week and a person's week", function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->w->sdoc->id, 'enabled_at' => '2026-10-01 00:00:00']);
    $suman = W::member($this->w->suman);
    AttendancePunch::factory()->at(AttendancePunch::IN, '2026-10-26 09:55:00')->create(['member_id' => $suman->id]);
    AttendancePunch::factory()->at(AttendancePunch::OUT, '2026-10-26 17:05:00')->create(['member_id' => $suman->id]);
    AttendancePunch::factory()->at(AttendancePunch::IN, '2026-10-27 10:40:00')->create(['member_id' => $suman->id]);
    AttendancePunch::factory()->at(AttendancePunch::OUT, '2026-10-27 17:00:00')->create(['member_id' => $suman->id]);

    $team = attendanceWorkRows(attendanceWorkPage($this->actingAs($this->w->admin)->get('/workspace/work/team?from=2026-10-26&to=2026-10-27'), 'Work/Team'));
    $person = attendanceWorkPage($this->actingAs($this->w->director)->get("/workspace/people/{$suman->id}/work?from=2026-10-26&to=2026-10-27"), 'Work/Person');

    expect($team['Suman Karki']['attendance'])->toMatchArray(['days_expected' => 2, 'days_present' => 2, 'days_late' => 1])
        ->and($person['attendance']['days_present'])->toBe(2)
        ->and($person['attendance']['minutes_worked'])->toBe(430 + 380);
});

it('gives the API the same attendance', function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->w->sdoc->id]);
    AttendancePunch::factory()->at(AttendancePunch::IN, '2026-10-28 09:50:00')->create(['member_id' => W::member($this->w->suman)->id]);

    $rows = collect($this->actingAs($this->w->admin, 'sanctum')
        ->getJson("/api/v1/workspace/organizations/{$this->w->sdoc->id}/work/day")
        ->assertOk()
        ->json('data.people'))->keyBy('member.name');

    expect($rows['Suman Karki']['attendance'])->toMatchArray(['state' => 'in', 'late' => false]);
});
