<?php

use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Queries\Attendance\MyAttendanceQuery;
use App\Services\Attendance\PunchService;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

const PUNCH_OFFICE_IP = '10.5.20.15';
const PUNCH_HOME_IP = '198.51.100.20';

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:52:10'));

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    AttendanceSetting::factory()->create(['organization_id' => $this->org->id]);
    AttendancePlace::factory()->create(['organization_id' => $this->org->id, 'name' => 'SDOC']);
    AttendanceNetwork::factory()->create(['organization_id' => $this->org->id, 'cidr' => '10.5.20.0/24']);

    $this->user = User::factory()->create();
    $this->member = Member::factory()->linkedTo($this->user)->create(['organization_id' => $this->org->id]);
    $this->actingAs($this->user);
});

function punchFrom(string $ip, array $data)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeader('User-Agent', 'PestBrowser/1.0')
        ->post('/workspace/attendance/punches', $data);
}

it('clocks in from the office network at the server time, keeping where it came from', function () {
    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'in', 'latitude' => 27.69836, 'longitude' => 85.3240, 'accuracy_m' => 18.6, 'punched_at' => '2026-11-02 08:00:00'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Clocked in at 9:52.']);

    $punch = AttendancePunch::query()->sole();

    expect($punch->member_id)->toBe($this->member->id)
        ->and($punch->organization_id)->toBe($this->org->id)
        ->and($punch->work_date->toDateString())->toBe('2026-11-02')
        ->and($punch->punched_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 09:52:10')
        ->and($punch->source)->toBe('web')
        ->and($punch->ip)->toBe(PUNCH_OFFICE_IP)
        ->and($punch->user_agent)->toBe('PestBrowser/1.0')
        ->and($punch->accuracy_m)->toBe(19)
        ->and($punch->place)->toBe('office_network')
        ->and($punch->distance_m)->toBe(40)
        ->and($punch->note)->toBeNull()
        ->and($punch->recorded_by)->toBeNull();
});

it('clocks in near the office off its network without a note', function () {
    punchFrom(PUNCH_HOME_IP, ['kind' => 'in', 'latitude' => 27.69836, 'longitude' => 85.3240, 'accuracy_m' => 10])
        ->assertSessionHasNoErrors();

    expect(AttendancePunch::query()->sole()->place)->toBe('near_office');
});

it('asks for a note outside the office, and takes the punch with one', function () {
    punchFrom(PUNCH_HOME_IP, ['kind' => 'in', 'latitude' => 27.7286, 'longitude' => 85.3240, 'accuracy_m' => 10])
        ->assertSessionHasErrors(['note' => "Say why you're away from the office."]);

    expect(AttendancePunch::query()->count())->toBe(0);

    punchFrom(PUNCH_HOME_IP, ['kind' => 'in', 'latitude' => 27.7286, 'longitude' => 85.3240, 'accuracy_m' => 10, 'note' => '  Meeting at the ministry  '])
        ->assertSessionHasNoErrors();

    $punch = AttendancePunch::query()->sole();

    expect($punch->place)->toBe('outside')
        ->and($punch->note)->toBe('Meeting at the ministry')
        ->and($punch->distance_m)->toBeGreaterThan(3000);
});

it('asks for a note when neither the network nor a location says where', function () {
    punchFrom(PUNCH_HOME_IP, ['kind' => 'in'])->assertSessionHasErrors(['note' => "Say why you're away from the office."]);
    punchFrom(PUNCH_HOME_IP, ['kind' => 'in', 'note' => '   '])->assertSessionHasErrors('note');
    punchFrom(PUNCH_HOME_IP, ['kind' => 'in', 'note' => 'Location blocked on this laptop'])->assertSessionHasNoErrors();

    expect(AttendancePunch::query()->sole()->place)->toBe('unknown');
});

it('judges an IPv6 office address as the office network', function () {
    AttendanceNetwork::factory()->create(['organization_id' => $this->org->id, 'cidr' => '2001:db8:20::/48']);

    punchFrom('2001:db8:20::abcd', ['kind' => 'in'])->assertSessionHasNoErrors();

    expect(AttendancePunch::query()->sole()->place)->toBe('office_network');
});

it('keeps the day in order: in, lunch once, out, nothing after', function () {
    $refused = fn (string $kind, string $message) => punchFrom(PUNCH_OFFICE_IP, ['kind' => $kind])
        ->assertSessionHasErrors(['kind' => $message]);
    $taken = fn (string $kind) => punchFrom(PUNCH_OFFICE_IP, ['kind' => $kind])->assertSessionHasNoErrors();

    $refused('lunch_start', 'Clock in first.');
    $refused('out', 'Clock in first.');
    $taken('in');
    $refused('in', "You've already clocked in today.");
    $refused('lunch_end', 'Start lunch first.');
    $taken('lunch_start');
    $refused('out', 'End lunch first.');
    $refused('lunch_start', "You've already started lunch today.");
    $taken('lunch_end');
    $refused('lunch_end', "You've already ended lunch today.");
    $taken('out');
    $refused('in', "You've already clocked out today.");
    $refused('lunch_start', "You've already clocked out today.");

    expect(AttendancePunch::query()->orderBy('id')->pluck('kind')->all())->toBe(['in', 'lunch_start', 'lunch_end', 'out']);
});

it('lets the day skip lunch and starts a new day the next morning', function () {
    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'in'])->assertSessionHasNoErrors();
    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'out'])->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:01:00'));
    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'in'])->assertSessionHasNoErrors();

    expect(AttendancePunch::query()->where('kind', 'in')->count())->toBe(2);
});

it('answers a double press that slips past the lock with "Already done."', function () {
    // The other press lands between the order check and the insert.
    AttendancePunch::creating(function (AttendancePunch $punch): void {
        DB::table('attendance_punches')->insert(Arr::except($punch->getAttributes(), ['id']) + ['created_at' => now(), 'updated_at' => now()]);
    });

    $result = app(PunchService::class)->punch($this->user, 'in', ['source' => 'web', 'ip' => PUNCH_OFFICE_IP]);

    expect($result->success)->toBeFalse()
        ->and($result->code)->toBe(422)
        ->and($result->data)->toBe(['kind' => 'Already done.']);
});

it('refuses to punch while the organization has attendance off', function () {
    AttendanceSetting::query()->update(['enabled' => false]);

    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'in'])->assertForbidden();

    expect(AttendancePunch::query()->count())->toBe(0);
});

it('refuses people of no organization, super-admins included', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    Member::factory()->linkedTo($boss)->create(['organization_id' => null]);

    $this->actingAs($boss);
    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'in'])->assertForbidden();

    expect(AttendancePunch::query()->count())->toBe(0);
});

it('signs a deactivated person out instead of punching', function () {
    $this->member->update(['is_active' => false]);

    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'in'])->assertRedirect();

    expect(AttendancePunch::query()->count())->toBe(0)
        ->and(WorkspaceAccess::canPunch($this->user->fresh()))->toBeFalse();
    $this->assertGuest();
});

it('validates the kind and the location', function () {
    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'nap'])->assertSessionHasErrors('kind');
    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'in', 'latitude' => 91, 'longitude' => 85])->assertSessionHasErrors('latitude');
    punchFrom(PUNCH_OFFICE_IP, ['kind' => 'in', 'latitude' => 27.7])->assertSessionHasErrors('longitude');

    expect(AttendancePunch::query()->count())->toBe(0);
});

it('shares the day with every page and follows it through each punch', function () {
    $state = function (): array {
        $props = [];
        $this->get('/workspace/my')->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props']['attendance'];
        });

        return $props;
    };

    $day = $state();
    expect($day['state'])->toBe('not_in')
        ->and($day['next'])->toBe(['in'])
        ->and($day['punches'])->toBe([])
        ->and($day['today'])->toMatchArray(['date' => '2026-11-02', 'expects_work' => true, 'start' => '10:00', 'end' => '16:00']);

    foreach ([
        ['in', 'in', ['lunch_start', 'out']],
        ['lunch_start', 'at_lunch', ['lunch_end']],
        ['lunch_end', 'in', ['out']],
        ['out', 'out', []],
    ] as [$kind, $expectedState, $expectedNext]) {
        punchFrom(PUNCH_OFFICE_IP, ['kind' => $kind, 'latitude' => 27.69836, 'longitude' => 85.3240])->assertSessionHasNoErrors();
        $day = $state();

        expect($day['state'])->toBe($expectedState)
            ->and($day['next'])->toBe($expectedNext)
            ->and(end($day['punches']))->toMatchArray([
                'kind' => $kind,
                'place' => 'office_network',
                'place_name' => 'SDOC',
                'distance_m' => 40,
                'note' => null,
            ]);
    }

    expect(array_column($day['punches'], 'kind'))->toBe(['in', 'lunch_start', 'lunch_end', 'out'])
        ->and($day['punches'][0]['at'])->toBe('2026-11-02T09:52:10+05:45');
});

it('shares no clock with someone who cannot punch', function () {
    AttendanceSetting::query()->update(['enabled' => false]);

    $this->get('/workspace/my')->assertInertia(fn (AssertableInertia $page) => $page->where('attendance', null));

    expect(app(MyAttendanceQuery::class)->get(null))->toBeNull();
});

it('shows only the user\'s own punches of today', function () {
    $colleague = Member::factory()->create(['organization_id' => $this->org->id]);
    AttendancePunch::factory()->for($colleague)->at('in', '2026-11-02 09:40:00')->create();
    AttendancePunch::factory()->for($this->member)->at('in', '2026-11-01 09:30:00')->create();

    expect(app(MyAttendanceQuery::class)->get($this->user))->toMatchArray(['punches' => [], 'state' => 'not_in']);
});
