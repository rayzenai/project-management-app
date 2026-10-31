<?php

use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:52:10'));
    $this->org = Organization::factory()->create();
    AttendanceSetting::factory()->create(['organization_id' => $this->org->id]);
    AttendancePlace::factory()->create(['organization_id' => $this->org->id, 'name' => 'SDOC']);
    AttendanceNetwork::factory()->create(['organization_id' => $this->org->id, 'cidr' => '10.5.20.0/24']);

    $this->user = User::factory()->create(['password' => bcrypt('secret-pass-1')]);
    $this->member = Member::factory()->linkedTo($this->user)->create(['organization_id' => $this->org->id]);
});

it('punches from the app and answers the punch and what comes next', function () {
    Sanctum::actingAs($this->user);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson('/api/v1/workspace/attendance/punches', ['kind' => 'in', 'latitude' => 27.69836, 'longitude' => 85.3240, 'accuracy_m' => 12])
        ->assertCreated()
        ->assertJsonPath('message', 'Clocked in at 9:52.')
        ->assertJsonPath('data.punch.kind', 'in')
        ->assertJsonPath('data.punch.at', '2026-11-02T09:52:10+05:45')
        ->assertJsonPath('data.punch.source', 'app')
        ->assertJsonPath('data.punch.place', 'near_office')
        ->assertJsonPath('data.punch.distance_m', 40)
        ->assertJsonPath('data.next', ['lunch_start', 'out']);

    expect(AttendancePunch::query()->sole())->source->toBe('app')->ip->toBe('198.51.100.20');
});

it('asks the app for a note away from the office, then takes it', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/workspace/attendance/punches', ['kind' => 'in', 'latitude' => 27.7286, 'longitude' => 85.3240])
        ->assertStatus(422)
        ->assertJsonPath('errors.note', "Say why you're away from the office.");

    $this->postJson('/api/v1/workspace/attendance/punches', ['kind' => 'in', 'latitude' => 27.7286, 'longitude' => 85.3240, 'note' => 'Field visit'])
        ->assertCreated()
        ->assertJsonPath('data.punch.place', 'outside')
        ->assertJsonPath('data.punch.note', 'Field visit');

    $this->postJson('/api/v1/workspace/attendance/punches', ['kind' => 'in', 'note' => 'Again'])
        ->assertStatus(422)
        ->assertJsonPath('errors.kind', "You've already clocked in today.");
});

it('gives the app the day: hours, punches, next step and state', function () {
    AttendancePunch::factory()->for($this->member)->at('in', '2026-11-02 09:40:00')->create();
    AttendancePunch::factory()->for($this->member)->at('lunch_start', '2026-11-02 13:00:00')->create();
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/workspace/attendance/me')
        ->assertOk()
        ->assertJsonPath('data.today.date', '2026-11-02')
        ->assertJsonPath('data.today.start', '10:00')
        ->assertJsonPath('data.state', 'at_lunch')
        ->assertJsonPath('data.next', ['lunch_end'])
        ->assertJsonPath('data.punches.1.kind', 'lunch_start');
});

it('says in the user payload whether the user may clock in', function () {
    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/user')->assertJsonPath('attendance_enabled', true);

    AttendanceSetting::query()->update(['enabled' => false]);
    $this->getJson('/api/v1/user')->assertJsonPath('attendance_enabled', false);
});

it('refuses the clock while attendance is off', function () {
    AttendanceSetting::query()->update(['enabled' => false]);
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/workspace/attendance/me')->assertForbidden();
    $this->postJson('/api/v1/workspace/attendance/punches', ['kind' => 'in', 'note' => 'x'])->assertForbidden();

    expect(AttendancePunch::query()->count())->toBe(0);
});

it('answers a deactivated person 401 and records nothing', function () {
    $this->member->update(['is_active' => false]);
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/workspace/attendance/punches', ['kind' => 'in', 'note' => 'x'])->assertUnauthorized();
    $this->getJson('/api/v1/workspace/attendance/me')->assertUnauthorized();

    expect(AttendancePunch::query()->count())->toBe(0);
});
