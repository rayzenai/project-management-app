<?php

use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\Organization;
use App\Support\Attendance\PunchPlace;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    // Singha Durbar, 150 m.
    $this->office = AttendancePlace::factory()->create(['organization_id' => $this->org->id, 'name' => 'SDOC']);
    AttendanceNetwork::factory()->create(['organization_id' => $this->org->id, 'cidr' => '10.5.20.0/24']);
    AttendanceNetwork::factory()->create(['organization_id' => $this->org->id, 'cidr' => '2001:db8:20::/48']);
});

it('judges an address in an office range as the office network, keeping the nearest place and distance', function () {
    expect(PunchPlace::judge($this->org->id, '10.5.20.77', 27.69836, 85.3240, 20))->toBe([
        'place' => 'office_network',
        'attendance_place_id' => $this->office->id,
        'distance_m' => 40,
    ])->and(PunchPlace::judge($this->org->id, '10.5.20.77', null, null, null))->toBe([
        'place' => 'office_network',
        'attendance_place_id' => null,
        'distance_m' => null,
    ]);
});

it('reads IPv6 ranges, single addresses and IPv4 written as IPv6', function () {
    AttendanceNetwork::factory()->create(['organization_id' => $this->org->id, 'cidr' => '203.0.113.7']);

    expect(PunchPlace::judge($this->org->id, '2001:db8:20:5::1', null, null, null)['place'])->toBe('office_network')
        ->and(PunchPlace::judge($this->org->id, '2001:db8:21::1', null, null, null)['place'])->toBe('unknown')
        ->and(PunchPlace::judge($this->org->id, '203.0.113.7', null, null, null)['place'])->toBe('office_network')
        ->and(PunchPlace::judge($this->org->id, '203.0.113.8', null, null, null)['place'])->toBe('unknown')
        ->and(PunchPlace::judge($this->org->id, '::ffff:10.5.20.9', null, null, null)['place'])->toBe('office_network');
});

it('judges a location within the radius as near the office', function () {
    expect(PunchPlace::judge($this->org->id, '198.51.100.1', 27.69836, 85.3240, 10))->toBe([
        'place' => 'near_office',
        'attendance_place_id' => $this->office->id,
        'distance_m' => 40,
    ]);
});

it('allows for the reading accuracy up to one more radius, never beyond', function () {
    // About 222 m away: outside 150 m, within reach with 100 m accuracy.
    $lat = 27.6980 + 0.002;

    expect(PunchPlace::judge($this->org->id, null, $lat, 85.3240, 5)['place'])->toBe('outside')
        ->and(PunchPlace::judge($this->org->id, null, $lat, 85.3240, 100)['place'])->toBe('near_office')
        // About 334 m: a 5 km reading still can't make it near (cap: twice the radius).
        ->and(PunchPlace::judge($this->org->id, null, 27.6980 + 0.003, 85.3240, 5000)['place'])->toBe('outside');
});

it('judges a location away from every place as outside, naming the nearest', function () {
    $other = AttendancePlace::factory()->create(['organization_id' => $this->org->id, 'name' => 'Annex', 'latitude' => 27.7300, 'longitude' => 85.3240]);

    expect(PunchPlace::judge($this->org->id, '198.51.100.1', 27.7320, 85.3240, 15))->toBe([
        'place' => 'outside',
        'attendance_place_id' => $other->id,
        'distance_m' => 222,
    ]);
});

it('judges no location off the office network as unknown', function () {
    expect(PunchPlace::judge($this->org->id, '198.51.100.1', null, null, null))->toBe([
        'place' => 'unknown',
        'attendance_place_id' => null,
        'distance_m' => null,
    ])->and(PunchPlace::judge($this->org->id, null, null, null, null)['place'])->toBe('unknown');
});

it('never uses another organization\'s places or networks', function () {
    $other = Organization::factory()->create();

    expect(PunchPlace::judge($other->id, '10.5.20.77', 27.69836, 85.3240, 10))->toBe([
        'place' => 'outside',
        'attendance_place_id' => null,
        'distance_m' => null,
    ]);
});
