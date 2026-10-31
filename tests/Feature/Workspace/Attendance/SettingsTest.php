<?php

use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->boss = User::factory()->create(['email' => 'boss@example.com']);

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'Ministry']);

    $person = function (Organization $org, array $attributes = []): User {
        $user = User::factory()->create();
        Member::factory()->linkedTo($user)->create(['organization_id' => $org->id, ...$attributes]);

        return $user;
    };
    $this->admin = $person($this->org, ['is_office_admin' => true]);
    $this->member = $person($this->org);
    $this->otherAdmin = $person($this->otherOrg, ['is_office_admin' => true]);
    $this->deactivatedAdmin = $person($this->org, ['is_office_admin' => true, 'is_active' => false]);
});

function settingsHours(array $overrides = []): array
{
    return [
        'workdays' => [5, 1, 2, 3, 4],
        'start_time' => '09:30',
        'end_time' => '17:30',
        'seasons' => [['name' => 'Winter', 'from' => '11-16', 'to' => '02-12', 'start' => '10:00', 'end' => '16:00']],
        'grace_minutes' => 10,
        ...$overrides,
    ];
}

it('lets a super-admin turn attendance on with the defaults, and off again', function () {
    $this->actingAs($this->boss)
        ->put("/workspace/organizations/{$this->org->id}/attendance", ['enabled' => true])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Attendance is on for SDOC.']);

    $setting = AttendanceSetting::query()->where('organization_id', $this->org->id)->sole();

    expect($setting->enabled)->toBeTrue()
        ->and($setting->enabled_by)->toBe($this->boss->id)
        ->and($setting->enabled_at)->not->toBeNull()
        ->and($setting->workdays)->toBe([1, 2, 3, 4, 5])
        ->and($setting->grace_minutes)->toBe(15)
        ->and(WorkspaceAccess::canPunch($this->member))->toBeTrue();

    $this->put("/workspace/organizations/{$this->org->id}/attendance", ['enabled' => false])->assertSessionHasNoErrors();

    expect($setting->fresh()->enabled)->toBeFalse()
        ->and($setting->fresh()->enabled_by)->toBe($this->boss->id)
        ->and(WorkspaceAccess::canPunch($this->member))->toBeFalse();
});

it('keeps the switch a super-admin matter', function (string $who) {
    $this->actingAs($this->{$who})
        ->put("/workspace/organizations/{$this->org->id}/attendance", ['enabled' => true])
        ->assertForbidden();

    expect(AttendanceSetting::query()->count())->toBe(0);
})->with(['admin', 'member', 'otherAdmin']);

it('opens the settings page for an organizational admin, on their own organization', function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->org->id, 'enabled_by' => $this->boss->id]);
    AttendancePlace::factory()->create(['organization_id' => $this->org->id, 'name' => 'SDOC']);
    AttendanceNetwork::factory()->create(['organization_id' => $this->org->id]);
    Holiday::factory()->create(['organization_id' => $this->org->id, 'date' => now()->addDays(3)->toDateString(), 'name' => 'Tihar']);
    Holiday::factory()->create(['organization_id' => $this->org->id, 'date' => now()->subYears(2)->toDateString(), 'name' => 'Old']);
    AttendancePlace::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Ministry']);

    $this->actingAs($this->admin)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/workspace/attendance/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Attendance/Settings')
            ->where('organization', ['id' => $this->org->id, 'name' => 'SDOC'])
            ->where('canEnable', false)
            ->where('organizations', [])
            ->where('yourAddress', '203.0.113.9')
            ->where('settings.enabled', true)
            ->where('settings.enabled_by', $this->boss->name)
            ->where('settings.start_time', '10:00')
            ->has('places', 1)
            ->where('places.0.name', 'SDOC')
            ->has('networks', 1)
            ->has('holidays', 1)
            ->where('holidays.0.name', 'Tihar'));
});

it('shows an organization never turned on with the defaults, off', function () {
    $this->actingAs($this->admin)->get('/workspace/attendance/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('settings.enabled', false)
            ->where('settings.workdays', [1, 2, 3, 4, 5])
            ->where('settings.seasons.0.name', 'Winter'));
});

it('lets a super-admin open any organization, with the switcher', function () {
    $this->actingAs($this->boss)->get("/workspace/attendance/settings?organization={$this->otherOrg->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organization.id', $this->otherOrg->id)
            ->where('canEnable', true)
            ->has('organizations', 2));

    // No organization of their own: the first by name.
    $this->get('/workspace/attendance/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organization.name', 'Ministry'));
});

it('keeps the settings page from members, other organizations and the deactivated', function () {
    $this->actingAs($this->member)->get('/workspace/attendance/settings')->assertForbidden();
    $this->actingAs($this->otherAdmin)->get("/workspace/attendance/settings?organization={$this->org->id}")->assertForbidden();
    $this->actingAs($this->admin)->get('/workspace/attendance/settings?organization=999999')->assertForbidden();
    $this->actingAs($this->deactivatedAdmin)->get('/workspace/attendance/settings')->assertRedirect();
});

it('lets an organizational admin save hours, before attendance is even on', function () {
    $this->actingAs($this->admin)
        ->put("/workspace/organizations/{$this->org->id}/attendance/settings", settingsHours())
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Hours saved.']);

    $setting = AttendanceSetting::query()->where('organization_id', $this->org->id)->sole();

    expect($setting->enabled)->toBeFalse()
        ->and($setting->workdays)->toBe([1, 2, 3, 4, 5])
        ->and($setting->start_time)->toBe('09:30')
        ->and($setting->end_time)->toBe('17:30')
        ->and($setting->seasons)->toEqual([['name' => 'Winter', 'from' => '11-16', 'to' => '02-12', 'start' => '10:00', 'end' => '16:00']])
        ->and($setting->grace_minutes)->toBe(10);
});

it('refuses hours that make no sense', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->put("/workspace/organizations/{$this->org->id}/attendance/settings", settingsHours($overrides))
        ->assertSessionHasErrors($field);

    expect(AttendanceSetting::query()->count())->toBe(0);
})->with([
    'no workdays' => [['workdays' => []], 'workdays'],
    'day 8' => [['workdays' => [1, 8]], 'workdays.1'],
    'end before start' => [['start_time' => '17:00', 'end_time' => '10:00'], 'end_time'],
    'not HH:MM' => [['start_time' => '9:00'], 'start_time'],
    'grace too long' => [['grace_minutes' => 121], 'grace_minutes'],
    'season of a day that does not exist' => [['seasons' => [['name' => 'X', 'from' => '02-30', 'to' => '03-10', 'start' => '10:00', 'end' => '16:00']]], 'seasons.0.from'],
    'season month 13' => [['seasons' => [['name' => 'X', 'from' => '13-01', 'to' => '03-10', 'start' => '10:00', 'end' => '16:00']]], 'seasons.0.from'],
    'season ending before it starts' => [['seasons' => [['name' => 'X', 'from' => '01-01', 'to' => '03-10', 'start' => '16:00', 'end' => '10:00']]], 'seasons.0.end'],
]);

it('keeps hours from members and other organizations\' admins', function () {
    $this->actingAs($this->member)->put("/workspace/organizations/{$this->org->id}/attendance/settings", settingsHours())->assertForbidden();
    $this->actingAs($this->otherAdmin)->put("/workspace/organizations/{$this->org->id}/attendance/settings", settingsHours())->assertForbidden();

    expect(AttendanceSetting::query()->count())->toBe(0);
});

it('adds, changes and removes office places', function () {
    $this->actingAs($this->admin);
    $base = "/workspace/organizations/{$this->org->id}/attendance/places";

    $this->post($base, ['name' => 'SDOC', 'latitude' => 27.698, 'longitude' => 85.324, 'radius_m' => 150])->assertSessionHasNoErrors();
    $place = AttendancePlace::query()->sole();

    $this->patch("{$base}/{$place->id}", ['name' => 'SDOC head office', 'latitude' => 27.7, 'longitude' => 85.33, 'radius_m' => 200])->assertSessionHasNoErrors();
    expect($place->fresh())->name->toBe('SDOC head office')->radius_m->toBe(200)->latitude->toBe(27.7);

    $this->post($base, ['name' => 'Bad', 'latitude' => 91, 'longitude' => 181, 'radius_m' => 10])
        ->assertSessionHasErrors(['latitude', 'longitude', 'radius_m']);

    $this->delete("{$base}/{$place->id}")->assertSessionHasNoErrors();
    expect(AttendancePlace::query()->count())->toBe(0);
});

it('adds office networks: IPv4 and IPv6 ranges and single addresses, never a range too wide', function () {
    $this->actingAs($this->admin);
    $base = "/workspace/organizations/{$this->org->id}/attendance/networks";

    foreach (['10.5.20.0/24', '203.0.113.7', '2001:DB8:20::/48', '2001:db8::1'] as $cidr) {
        $this->post($base, ['label' => 'Office', 'cidr' => $cidr])->assertSessionHasNoErrors();
    }

    expect(AttendanceNetwork::query()->pluck('cidr')->all())->toBe(['10.5.20.0/24', '203.0.113.7', '2001:db8:20::/48', '2001:db8::1']);

    foreach (['10.5.20.0/33', '10.5.300.0/24', 'office', '10.0.0.0/4', '2001:db8::/12', '10.0.0.0/x'] as $cidr) {
        $this->post($base, ['label' => 'Office', 'cidr' => $cidr])->assertSessionHasErrors('cidr');
    }

    $network = AttendanceNetwork::query()->firstOrFail();
    $this->patch("{$base}/{$network->id}", ['label' => 'Wi-Fi', 'cidr' => '10.5.21.0/24'])->assertSessionHasNoErrors();
    expect($network->fresh()->cidr)->toBe('10.5.21.0/24');

    $this->delete("{$base}/{$network->id}")->assertSessionHasNoErrors();
    expect(AttendanceNetwork::query()->count())->toBe(3);
});

it('adds holidays once per date', function () {
    $this->actingAs($this->admin);
    $base = "/workspace/organizations/{$this->org->id}/attendance/holidays";

    $this->post($base, ['date' => '2026-11-09', 'name' => 'Tihar'])->assertSessionHasNoErrors();
    $this->post($base, ['date' => '2026-11-09', 'name' => 'Again'])->assertSessionHasErrors(['date' => 'That date is already a holiday.']);
    $this->post($base, ['date' => '2026-11-10', 'name' => 'Bhai Tika'])->assertSessionHasNoErrors();

    $tihar = Holiday::query()->where('name', 'Tihar')->sole();
    $this->patch("{$base}/{$tihar->id}", ['date' => '2026-11-10', 'name' => 'Tihar'])->assertSessionHasErrors('date');
    $this->patch("{$base}/{$tihar->id}", ['date' => '2026-11-09', 'name' => 'Laxmi Puja'])->assertSessionHasNoErrors();
    expect($tihar->fresh()->name)->toBe('Laxmi Puja');

    // Another organization's holiday on the same day is its own.
    $this->actingAs($this->otherAdmin)
        ->post("/workspace/organizations/{$this->otherOrg->id}/attendance/holidays", ['date' => '2026-11-09', 'name' => 'Tihar'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->delete("{$base}/{$tihar->id}")->assertSessionHasNoErrors();
    expect(Holiday::query()->count())->toBe(2);
});

it('never lets anyone reach another organization\'s places, networks or holidays', function () {
    $place = AttendancePlace::factory()->create(['organization_id' => $this->org->id]);
    $network = AttendanceNetwork::factory()->create(['organization_id' => $this->org->id]);
    $holiday = Holiday::factory()->create(['organization_id' => $this->org->id]);
    $mine = "/workspace/organizations/{$this->otherOrg->id}/attendance";
    $theirs = "/workspace/organizations/{$this->org->id}/attendance";

    $this->actingAs($this->otherAdmin);

    // Through the other organization's own route.
    $this->post("{$theirs}/places", ['name' => 'X', 'latitude' => 1, 'longitude' => 1, 'radius_m' => 100])->assertForbidden();
    $this->patch("{$theirs}/places/{$place->id}", ['name' => 'X', 'latitude' => 1, 'longitude' => 1, 'radius_m' => 100])->assertForbidden();
    $this->delete("{$theirs}/networks/{$network->id}")->assertForbidden();
    $this->delete("{$theirs}/holidays/{$holiday->id}")->assertForbidden();

    // Their records put under the admin's own organization.
    $this->patch("{$mine}/places/{$place->id}", ['name' => 'X', 'latitude' => 1, 'longitude' => 1, 'radius_m' => 100])->assertForbidden();
    $this->delete("{$mine}/places/{$place->id}")->assertForbidden();
    $this->patch("{$mine}/networks/{$network->id}", ['label' => 'X', 'cidr' => '10.0.0.0/24'])->assertForbidden();
    $this->delete("{$mine}/holidays/{$holiday->id}")->assertForbidden();

    expect($place->fresh()->name)->toBe('Head office')
        ->and($network->fresh())->not->toBeNull()
        ->and($holiday->fresh())->not->toBeNull()
        ->and(AttendancePlace::query()->count())->toBe(1);
});

it('keeps places, networks and holidays from members', function () {
    $place = AttendancePlace::factory()->create(['organization_id' => $this->org->id]);
    $base = "/workspace/organizations/{$this->org->id}/attendance";

    $this->actingAs($this->member);
    $this->post("{$base}/places", ['name' => 'X', 'latitude' => 1, 'longitude' => 1, 'radius_m' => 100])->assertForbidden();
    $this->delete("{$base}/places/{$place->id}")->assertForbidden();
    $this->post("{$base}/networks", ['label' => 'X', 'cidr' => '10.0.0.0/24'])->assertForbidden();
    $this->post("{$base}/holidays", ['date' => '2026-12-25', 'name' => 'X'])->assertForbidden();

    expect(AttendancePlace::query()->count())->toBe(1)
        ->and(AttendanceNetwork::query()->count())->toBe(0)
        ->and(Holiday::query()->count())->toBe(0);
});

it('lets a super-admin manage any organization\'s settings', function () {
    $this->actingAs($this->boss)
        ->put("/workspace/organizations/{$this->otherOrg->id}/attendance/settings", settingsHours())
        ->assertSessionHasNoErrors();
    $this->post("/workspace/organizations/{$this->otherOrg->id}/attendance/places", ['name' => 'Ministry', 'latitude' => 27.7, 'longitude' => 85.3, 'radius_m' => 150])
        ->assertSessionHasNoErrors();

    expect(AttendanceSetting::query()->where('organization_id', $this->otherOrg->id)->exists())->toBeTrue()
        ->and(AttendancePlace::query()->where('organization_id', $this->otherOrg->id)->count())->toBe(1);
});
