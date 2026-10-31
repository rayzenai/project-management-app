<?php

use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * The JSON twins of leave and kaaj, through Sanctum: the same rules as the
 * web (LeaveTest), the main cases. Monday 2 November 2026. SDOC: a
 * director with an engineer below, a peer; MoF has its own admin.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-11-02 11:00:00');

    $this->sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $this->mof = Organization::factory()->create(['name' => 'MoF']);
    AttendanceSetting::factory()->create(['organization_id' => $this->sdoc->id]);
    AttendanceSetting::factory()->create(['organization_id' => $this->mof->id]);

    $person = function (Organization $organization, string $name, array $attributes = []): Member {
        $user = User::factory()->create(['name' => $name]);

        return Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, ...$attributes]);
    };

    $this->mofAdmin = $person($this->mof, 'MoF Admin', ['is_office_admin' => true]);
    $this->director = $person($this->sdoc, 'Director');
    $this->peer = $person($this->sdoc, 'Peer');
    $this->engineer = $person($this->sdoc, 'Engineer', ['reports_to_id' => $this->director->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('asks for kaaj and returns it, 201', function () {
    Sanctum::actingAs($this->engineer->user, ['*']);

    $this->postJson('/api/v1/workspace/leave', ['kind' => 'kaaj', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-06', 'reason' => 'Site visit', 'place' => 'Pokhara'])
        ->assertCreated()
        ->assertJsonPath('message', 'Kaaj requested.')
        ->assertJsonPath('data.kind', 'kaaj')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.days', 3)
        ->assertJsonPath('data.place', 'Pokhara')
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonPath('data.member.name', 'Engineer');
});

it('answers the rules with 422 and field errors', function () {
    Sanctum::actingAs($this->engineer->user, ['*']);
    LeaveRequest::factory()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05']);

    $this->postJson('/api/v1/workspace/leave', ['kind' => 'kaaj', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-06', 'reason' => 'Site visit'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('place');

    $this->postJson('/api/v1/workspace/leave', ['kind' => 'leave', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-06', 'reason' => 'Rest'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.starts_on', 'You already have leave or kaaj on some of these days.');
});

it('refuses asking with attendance off', function () {
    AttendanceSetting::query()->where('organization_id', $this->sdoc->id)->update(['enabled' => false]);
    Sanctum::actingAs($this->engineer->user, ['*']);

    $this->postJson('/api/v1/workspace/leave', ['kind' => 'leave', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04', 'reason' => 'Rest'])->assertForbidden();
});

it('lists mine, those to decide and whom I may record for', function () {
    LeaveRequest::factory()->create(['member_id' => $this->engineer->id]);
    LeaveRequest::factory()->create(['member_id' => $this->director->id, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-09']);
    Sanctum::actingAs($this->director->user, ['*']);

    $this->getJson('/api/v1/workspace/leave')
        ->assertOk()
        ->assertJsonPath('data.can_ask', true)
        ->assertJsonCount(1, 'data.mine')
        ->assertJsonPath('data.mine.0.starts_on', '2026-11-09')
        ->assertJsonCount(1, 'data.to_decide')
        ->assertJsonPath('data.to_decide.0.member.name', 'Engineer')
        ->assertJsonPath('data.can_record_for', [['id' => $this->engineer->id, 'name' => 'Engineer', 'designation' => null, 'organization' => 'SDOC']]);
});

it('approves and rejects for someone above only', function () {
    $first = LeaveRequest::factory()->create(['member_id' => $this->engineer->id]);
    $second = LeaveRequest::factory()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);

    Sanctum::actingAs($this->peer->user, ['*']);
    $this->postJson("/api/v1/workspace/leave/{$first->id}/approve")->assertForbidden();

    Sanctum::actingAs($this->engineer->user, ['*']);
    $this->postJson("/api/v1/workspace/leave/{$first->id}/approve")->assertForbidden();

    Sanctum::actingAs($this->mofAdmin->user, ['*']);
    $this->postJson("/api/v1/workspace/leave/{$first->id}/reject")->assertForbidden();

    Sanctum::actingAs($this->director->user, ['*']);
    $this->postJson("/api/v1/workspace/leave/{$first->id}/approve", ['note' => 'Fine'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decided_by.name', 'Director')
        ->assertJsonPath('data.decision_note', 'Fine');
    $this->postJson("/api/v1/workspace/leave/{$second->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/workspace/leave/{$second->id}/approve")->assertUnprocessable();
});

it('cancels one\'s own, and refuses anyone else', function () {
    $leave = LeaveRequest::factory()->create(['member_id' => $this->engineer->id]);

    Sanctum::actingAs($this->director->user, ['*']);
    $this->postJson("/api/v1/workspace/leave/{$leave->id}/cancel")->assertForbidden();

    Sanctum::actingAs($this->engineer->user, ['*']);
    $this->postJson("/api/v1/workspace/leave/{$leave->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/workspace/leave/{$leave->id}/cancel")->assertUnprocessable();
});

it('records approved leave for someone below, 201, and refuses a peer', function () {
    Sanctum::actingAs($this->director->user, ['*']);

    $this->postJson("/api/v1/workspace/members/{$this->engineer->id}/leave", ['kind' => 'leave', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'reason' => 'Sick'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decided_by.name', 'Director')
        ->assertJsonPath('data.can_cancel', false);

    $this->postJson("/api/v1/workspace/members/{$this->peer->id}/leave", ['kind' => 'leave', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'reason' => 'Sick'])
        ->assertForbidden();
});
