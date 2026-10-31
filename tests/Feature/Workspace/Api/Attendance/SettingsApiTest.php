<?php

use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create();

    $person = function (Organization $org, array $attributes = []): User {
        $user = User::factory()->create();
        Member::factory()->linkedTo($user)->create(['organization_id' => $org->id, ...$attributes]);

        return $user;
    };
    $this->admin = $person($this->org, ['is_office_admin' => true]);
    $this->member = $person($this->org);
    $this->otherAdmin = $person($this->otherOrg, ['is_office_admin' => true]);
    $this->base = "/api/v1/workspace/organizations/{$this->org->id}/attendance";
});

it('lets a super-admin turn attendance on and off', function () {
    Sanctum::actingAs($this->boss);

    $this->putJson($this->base, ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('message', 'Attendance is on for SDOC.')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.workdays', [1, 2, 3, 4, 5]);

    $this->putJson($this->base, ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    $this->putJson($this->base, [])->assertStatus(422)->assertJsonValidationErrors('enabled');
});

it('keeps the switch from organizational admins', function () {
    Sanctum::actingAs($this->admin);

    $this->putJson($this->base, ['enabled' => true])->assertForbidden();

    expect(AttendanceSetting::query()->count())->toBe(0);
});

it('reads and saves the settings for whoever administers the organization', function () {
    AttendancePlace::factory()->create(['organization_id' => $this->org->id, 'name' => 'SDOC']);
    Sanctum::actingAs($this->admin);

    $this->getJson("{$this->base}/settings")
        ->assertOk()
        ->assertJsonPath('data.organization.name', 'SDOC')
        ->assertJsonPath('data.settings.enabled', false)
        ->assertJsonPath('data.places.0.name', 'SDOC')
        ->assertJsonPath('data.networks', [])
        ->assertJsonPath('data.holidays', []);

    $this->putJson("{$this->base}/settings", [
        'workdays' => [1, 2, 3, 4, 5, 6],
        'start_time' => '10:00',
        'end_time' => '17:00',
        'seasons' => [],
        'grace_minutes' => 0,
    ])->assertOk()->assertJsonPath('data.workdays', [1, 2, 3, 4, 5, 6])->assertJsonPath('data.seasons', []);

    $this->putJson("{$this->base}/settings", ['workdays' => [], 'start_time' => '10:00', 'end_time' => '09:00', 'grace_minutes' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['workdays', 'seasons', 'end_time']);
});

it('keeps the settings from members and other organizations', function () {
    Sanctum::actingAs($this->member);
    $this->getJson("{$this->base}/settings")->assertForbidden();

    Sanctum::actingAs($this->otherAdmin);
    $this->getJson("{$this->base}/settings")->assertForbidden();
    $this->putJson("{$this->base}/settings", ['workdays' => [1], 'start_time' => '10:00', 'end_time' => '17:00', 'seasons' => [], 'grace_minutes' => 0])
        ->assertForbidden();
});

it('adds, changes and removes places, networks and holidays', function () {
    Sanctum::actingAs($this->admin);

    $placeId = $this->postJson("{$this->base}/places", ['name' => 'SDOC', 'latitude' => 27.698, 'longitude' => 85.324, 'radius_m' => 150])
        ->assertCreated()->assertJsonPath('data.name', 'SDOC')->json('data.id');
    $this->patchJson("{$this->base}/places/{$placeId}", ['name' => 'SDOC', 'latitude' => 27.698, 'longitude' => 85.324, 'radius_m' => 300])
        ->assertOk()->assertJsonPath('data.radius_m', 300);

    $networkId = $this->postJson("{$this->base}/networks", ['label' => 'Wi-Fi', 'cidr' => '2001:db8:20::/48'])
        ->assertCreated()->assertJsonPath('data.cidr', '2001:db8:20::/48')->json('data.id');
    $this->postJson("{$this->base}/networks", ['label' => 'Everything', 'cidr' => '0.0.0.0/0'])
        ->assertStatus(422)->assertJsonValidationErrors('cidr');

    $holidayId = $this->postJson("{$this->base}/holidays", ['date' => '2026-11-09', 'name' => 'Tihar'])
        ->assertCreated()->assertJsonPath('data.date', '2026-11-09')->json('data.id');
    $this->postJson("{$this->base}/holidays", ['date' => '2026-11-09', 'name' => 'Tihar'])
        ->assertStatus(422)->assertJsonValidationErrors(['date' => 'That date is already a holiday.']);

    $this->deleteJson("{$this->base}/places/{$placeId}")->assertOk();
    $this->deleteJson("{$this->base}/networks/{$networkId}")->assertOk();
    $this->deleteJson("{$this->base}/holidays/{$holidayId}")->assertOk();

    expect(AttendancePlace::query()->count() + AttendanceNetwork::query()->count() + Holiday::query()->count())->toBe(0);
});

it('never reaches another organization\'s records through the API', function () {
    $place = AttendancePlace::factory()->create(['organization_id' => $this->org->id]);
    $holiday = Holiday::factory()->create(['organization_id' => $this->org->id]);
    $mine = "/api/v1/workspace/organizations/{$this->otherOrg->id}/attendance";
    Sanctum::actingAs($this->otherAdmin);

    $this->deleteJson("{$this->base}/places/{$place->id}")->assertForbidden();
    $this->deleteJson("{$mine}/places/{$place->id}")->assertForbidden();
    $this->patchJson("{$mine}/holidays/{$holiday->id}", ['date' => '2026-12-01', 'name' => 'X'])->assertForbidden();

    expect($place->fresh())->not->toBeNull()
        ->and($holiday->fresh()->name)->toBe('Public holiday');
});
