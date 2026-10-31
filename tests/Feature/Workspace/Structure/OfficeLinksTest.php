<?php

use App\Models\AttendanceSetting;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\OfficeLinks;
use App\Support\WorkspaceAccess;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->org = Organization::factory()->create();
    $person = fn (array $attributes = []) => Member::factory()->linkedTo(User::factory()->create())->create(['organization_id' => $this->org->id, ...$attributes]);
    $this->director = $person();
    $this->engineer = $person(['reports_to_id' => $this->director->id]);
    $this->admin = $person(['is_office_admin' => true]);
    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
});

it('offers someone with nobody below them the organogram and their own week', function () {
    expect(OfficeLinks::for($this->engineer->user))->toBe([
        'organogram' => true,
        'attendance' => false,
        'attendance_settings' => false,
        'leave' => false,
        'team_work' => false,
        'my_work_member_id' => $this->engineer->id,
        'maintenance' => false,
    ]);
});

it('adds attendance, leave and the work reports once there is attendance and people below', function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->org->id]);

    expect(OfficeLinks::for($this->director->user))->toMatchArray(['attendance' => true, 'leave' => true, 'team_work' => true, 'attendance_settings' => false])
        ->and(OfficeLinks::for($this->engineer->user))->toMatchArray(['attendance' => false, 'leave' => true, 'team_work' => false])
        ->and(OfficeLinks::for($this->admin->user))->toMatchArray(['attendance' => true, 'attendance_settings' => true, 'team_work' => true, 'maintenance' => true]);
});

it('gives a deactivated person no office links', function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->org->id]);
    $this->director->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(OfficeLinks::for($this->director->user))->toMatchArray([
        'organogram' => false, 'attendance' => false, 'leave' => false, 'team_work' => false, 'my_work_member_id' => null, 'maintenance' => false,
    ]);
});

it('offers a super-admin settings everywhere and the register once any organization has attendance', function () {
    expect(OfficeLinks::for($this->boss))->toMatchArray(['organogram' => true, 'attendance_settings' => true, 'team_work' => true, 'attendance' => false, 'maintenance' => true]);

    AttendanceSetting::factory()->create(['organization_id' => $this->org->id]);
    WorkspaceAccess::flush();

    expect(OfficeLinks::for($this->boss)['attendance'])->toBeTrue();
});

it('shares the links with the web and the API', function () {
    $this->actingAs($this->director->user)->get('/workspace/my')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('office.organogram', true)->where('office.my_work_member_id', $this->director->id));

    $this->actingAs($this->director->user, 'sanctum')->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('office.team_work', true);
});
