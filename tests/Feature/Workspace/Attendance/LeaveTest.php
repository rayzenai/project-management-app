<?php

use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/*
 * Leave and kaaj (/workspace/leave): asking, deciding, cancelling and
 * recording, and who may do each. Monday 2 November 2026. SDOC: the ED,
 * a director with an engineer below her and an intern below him, a peer
 * of the director, an admin. MoF has its own admin.
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

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = $person($this->sdoc, 'Admin', ['is_office_admin' => true]);
    $this->mofAdmin = $person($this->mof, 'MoF Admin', ['is_office_admin' => true]);
    $this->ed = $person($this->sdoc, 'Executive Director');
    $this->director = $person($this->sdoc, 'Director', ['reports_to_id' => $this->ed->id]);
    $this->peer = $person($this->sdoc, 'Peer', ['reports_to_id' => $this->ed->id]);
    $this->engineer = $person($this->sdoc, 'Engineer', ['reports_to_id' => $this->director->id]);
    $this->intern = $person($this->sdoc, 'Intern', ['reports_to_id' => $this->engineer->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return array<string, mixed>
 */
function leaveAsk(array $overrides = []): array
{
    return ['kind' => 'leave', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-05', 'reason' => 'Family wedding', ...$overrides];
}

// ---- Asking ----

it('lets someone ask for leave for themselves', function () {
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk())
        ->assertRedirect()
        ->assertSessionHas('workspace_flash', fn (array $flash) => $flash['success'] && $flash['message'] === 'Leave requested.');

    $leave = LeaveRequest::query()->sole();

    expect($leave->member_id)->toBe($this->engineer->id)
        ->and($leave->organization_id)->toBe($this->sdoc->id)
        ->and($leave->status)->toBe('pending')
        ->and($leave->starts_on->toDateString())->toBe('2026-11-04')
        ->and($leave->ends_on->toDateString())->toBe('2026-11-05')
        ->and($leave->requested_by)->toBe($this->engineer->user_id)
        ->and($leave->place)->toBeNull();
});

it('asks for kaaj with a place, and refuses kaaj without one', function () {
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['kind' => 'kaaj']))->assertSessionHasErrors('place');
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['kind' => 'kaaj', 'place' => 'Pokhara']))->assertSessionHasNoErrors();

    expect(LeaveRequest::query()->sole()->only(['kind', 'place']))->toBe(['kind' => 'kaaj', 'place' => 'Pokhara']);
});

it('refuses a reason left out, the days backwards, and more than 90 days', function () {
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['reason' => '']))->assertSessionHasErrors('reason');
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['starts_on' => '2026-11-05', 'ends_on' => '2026-11-04']))->assertSessionHasErrors('ends_on');
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['kind' => 'holiday']))->assertSessionHasErrors('kind');
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['starts_on' => '2026-11-04', 'ends_on' => '2027-02-02']))->assertSessionHasErrors('ends_on');
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['starts_on' => '2026-11-04', 'ends_on' => '2027-02-01']))->assertSessionHasNoErrors();

    expect(LeaveRequest::query()->count())->toBe(1);
});

it('refuses days overlapping one\'s own pending or approved leave, but not rejected or cancelled', function () {
    LeaveRequest::factory()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-06']);
    LeaveRequest::factory()->approved()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);
    LeaveRequest::factory()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'status' => 'rejected']);
    LeaveRequest::factory()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-13', 'ends_on' => '2026-11-13', 'status' => 'cancelled']);
    LeaveRequest::factory()->approved()->create(['member_id' => $this->intern->id, 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);

    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk())->assertSessionHasErrors('starts_on');
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['starts_on' => '2026-11-09', 'ends_on' => '2026-11-11']))->assertSessionHasErrors('starts_on');
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['starts_on' => '2026-11-12', 'ends_on' => '2026-11-13']))->assertSessionHasNoErrors();
    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk(['starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']))->assertSessionHasNoErrors();

    expect(LeaveRequest::query()->where('member_id', $this->engineer->id)->count())->toBe(6);
});

it('refuses asking while attendance is off, outside an organization, or deactivated', function () {
    AttendanceSetting::query()->where('organization_id', $this->sdoc->id)->update(['enabled' => false]);

    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk())->assertForbidden();
    $this->actingAs($this->boss)->post('/workspace/leave', leaveAsk())->assertForbidden();
    $this->actingAs(User::factory()->create())->post('/workspace/leave', leaveAsk())->assertForbidden();

    AttendanceSetting::query()->where('organization_id', $this->sdoc->id)->update(['enabled' => true]);
    $this->engineer->update(['is_active' => false]);
    WorkspaceAccess::flush();

    $this->actingAs($this->engineer->user)->post('/workspace/leave', leaveAsk())->assertRedirect();

    expect(LeaveRequest::query()->count())->toBe(0);
});

// ---- Deciding ----

it('lets someone above approve, with a note, recording who decided and when', function () {
    $leave = LeaveRequest::factory()->create(['member_id' => $this->intern->id]);

    $this->actingAs($this->director->user)->post("/workspace/leave/{$leave->id}/approve", ['note' => 'Enjoy'])
        ->assertSessionHas('workspace_flash', fn (array $flash) => $flash['message'] === 'Approved.');

    $leave->refresh();

    expect($leave->status)->toBe('approved')
        ->and($leave->decided_by)->toBe($this->director->id)
        ->and($leave->decided_at?->toDateTimeString())->toBe('2026-11-02 11:00:00')
        ->and($leave->decision_note)->toBe('Enjoy');
});

it('lets the direct higher authority, an admin and a super-admin reject', function () {
    foreach ([$this->engineer->user, $this->admin->user, $this->boss] as $decider) {
        $leave = LeaveRequest::factory()->create(['member_id' => $this->intern->id]);

        $this->actingAs($decider)->post("/workspace/leave/{$leave->id}/reject", ['note' => 'Busy week'])->assertSessionHasNoErrors();

        expect($leave->refresh()->status)->toBe('rejected')
            ->and($leave->decision_note)->toBe('Busy week');
    }

    expect(LeaveRequest::query()->where('member_id', $this->intern->id)->latest('id')->first()->decided_by)->toBeNull(); // the super-admin has no member
});

it('never lets someone decide their own, a peer\'s, someone above them, or another organization\'s', function () {
    $engineers = LeaveRequest::factory()->create(['member_id' => $this->engineer->id]);
    $admins = LeaveRequest::factory()->create(['member_id' => $this->admin->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']);

    $this->actingAs($this->engineer->user)->post("/workspace/leave/{$engineers->id}/approve")->assertForbidden();
    $this->actingAs($this->intern->user)->post("/workspace/leave/{$engineers->id}/approve")->assertForbidden();
    $this->actingAs($this->peer->user)->post("/workspace/leave/{$engineers->id}/approve")->assertForbidden();
    $this->actingAs($this->mofAdmin->user)->post("/workspace/leave/{$engineers->id}/approve")->assertForbidden();
    $this->actingAs($this->admin->user)->post("/workspace/leave/{$admins->id}/approve")->assertForbidden();

    expect($engineers->refresh()->status)->toBe('pending')
        ->and($admins->refresh()->status)->toBe('pending');
});

it('never lets a deactivated higher authority decide', function () {
    $leave = LeaveRequest::factory()->create(['member_id' => $this->engineer->id]);
    $this->director->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canDecideLeave($this->director->user, $leave))->toBeFalse();

    $this->actingAs($this->director->user)->post("/workspace/leave/{$leave->id}/approve")->assertRedirect();

    expect($leave->refresh()->status)->toBe('pending');
});

it('refuses deciding a request already decided or cancelled', function () {
    $leave = LeaveRequest::factory()->approved()->create(['member_id' => $this->engineer->id]);

    $this->actingAs($this->director->user)->post("/workspace/leave/{$leave->id}/reject")
        ->assertSessionHas('workspace_flash', fn (array $flash) => $flash['success'] === false);

    expect($leave->refresh()->status)->toBe('approved');
});

// ---- Cancelling ----

it('lets someone cancel their own request while pending, or approved and not started', function () {
    $pending = LeaveRequest::factory()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-10-30', 'ends_on' => '2026-10-30']);
    $upcoming = LeaveRequest::factory()->approved()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03']);

    $this->actingAs($this->engineer->user)->post("/workspace/leave/{$pending->id}/cancel")->assertSessionHasNoErrors();
    $this->actingAs($this->engineer->user)->post("/workspace/leave/{$upcoming->id}/cancel")->assertSessionHasNoErrors();

    expect($pending->refresh()->status)->toBe('cancelled')
        ->and($upcoming->refresh()->status)->toBe('cancelled');
});

it('refuses cancelling approved leave that has started, a decided request, or someone else\'s', function () {
    $started = LeaveRequest::factory()->approved()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03']);
    $rejected = LeaveRequest::factory()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'status' => 'rejected']);
    $interns = LeaveRequest::factory()->create(['member_id' => $this->intern->id]);

    $this->actingAs($this->engineer->user)->post("/workspace/leave/{$started->id}/cancel")
        ->assertSessionHas('workspace_flash', fn (array $flash) => $flash['success'] === false);
    $this->actingAs($this->engineer->user)->post("/workspace/leave/{$rejected->id}/cancel")
        ->assertSessionHas('workspace_flash', fn (array $flash) => $flash['success'] === false);
    $this->actingAs($this->engineer->user)->post("/workspace/leave/{$interns->id}/cancel")->assertForbidden();
    $this->actingAs($this->admin->user)->post("/workspace/leave/{$interns->id}/cancel")->assertForbidden();

    expect($started->refresh()->status)->toBe('approved')
        ->and($rejected->refresh()->status)->toBe('rejected')
        ->and($interns->refresh()->status)->toBe('pending');
});

// ---- Recording ----

it('lets someone above record approved leave or kaaj directly', function () {
    $this->actingAs($this->director->user)->post("/workspace/members/{$this->intern->id}/leave", leaveAsk(['kind' => 'kaaj', 'place' => 'Biratnagar']))
        ->assertSessionHas('workspace_flash', fn (array $flash) => $flash['message'] === 'Kaaj recorded for Intern.');

    $leave = LeaveRequest::query()->sole();

    expect($leave->only(['member_id', 'kind', 'place', 'status', 'decided_by', 'requested_by']))->toBe([
        'member_id' => $this->intern->id,
        'kind' => 'kaaj',
        'place' => 'Biratnagar',
        'status' => 'approved',
        'decided_by' => $this->director->id,
        'requested_by' => $this->director->user_id,
    ]);
});

it('records with the same rules as asking', function () {
    LeaveRequest::factory()->create(['member_id' => $this->intern->id, 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05']);

    $this->actingAs($this->engineer->user)->post("/workspace/members/{$this->intern->id}/leave", leaveAsk())->assertSessionHasErrors('starts_on');
    $this->actingAs($this->engineer->user)->post("/workspace/members/{$this->intern->id}/leave", leaveAsk(['kind' => 'kaaj', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10']))->assertSessionHasErrors('place');

    expect(LeaveRequest::query()->count())->toBe(1);
});

it('never lets someone record leave for themselves, a peer, someone above, or another organization', function () {
    $outsider = Member::factory()->create(['organization_id' => $this->mof->id]);
    $gone = Member::factory()->create(['organization_id' => $this->sdoc->id, 'reports_to_id' => $this->director->id, 'is_active' => false]);

    $this->actingAs($this->director->user)->post("/workspace/members/{$this->director->id}/leave", leaveAsk())->assertForbidden();
    $this->actingAs($this->director->user)->post("/workspace/members/{$this->peer->id}/leave", leaveAsk())->assertForbidden();
    $this->actingAs($this->intern->user)->post("/workspace/members/{$this->engineer->id}/leave", leaveAsk())->assertForbidden();
    $this->actingAs($this->admin->user)->post("/workspace/members/{$outsider->id}/leave", leaveAsk())->assertForbidden();
    $this->actingAs($this->director->user)->post("/workspace/members/{$gone->id}/leave", leaveAsk())->assertForbidden();

    expect(LeaveRequest::query()->count())->toBe(0);
});

it('never lets anyone record leave while attendance is off', function () {
    AttendanceSetting::query()->where('organization_id', $this->sdoc->id)->update(['enabled' => false]);

    $this->actingAs($this->director->user)->post("/workspace/members/{$this->intern->id}/leave", leaveAsk())->assertForbidden();
    $this->actingAs($this->boss)->post("/workspace/members/{$this->intern->id}/leave", leaveAsk())->assertForbidden();
});

// ---- The page ----

it('lists my requests, newest first, upcoming and the last 60 days', function () {
    LeaveRequest::factory()->approved()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-02']); // too old
    $recent = LeaveRequest::factory()->approved()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-10', 'decided_by' => $this->director->id]);
    $upcoming = LeaveRequest::factory()->create(['member_id' => $this->engineer->id, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-03']);
    LeaveRequest::factory()->create(['member_id' => $this->intern->id]);

    $this->actingAs($this->engineer->user)->get('/workspace/leave')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Attendance/Leave')
            ->where('can_ask', true)
            ->has('mine', 2)
            ->where('mine.0.id', $upcoming->id)
            ->where('mine.0.days', 3)
            ->where('mine.0.can_cancel', true)
            ->where('mine.1.id', $recent->id)
            ->where('mine.1.decided_by.name', 'Director')
            ->where('mine.1.can_cancel', false)
            ->has('to_decide', 1)
            ->where('to_decide.0.member.name', 'Intern'));
});

it('shows a director the pending requests of everyone below them, and nobody else\'s', function () {
    LeaveRequest::factory()->create(['member_id' => $this->engineer->id]);
    LeaveRequest::factory()->create(['member_id' => $this->intern->id]);
    LeaveRequest::factory()->create(['member_id' => $this->peer->id]);
    LeaveRequest::factory()->create(['member_id' => $this->director->id]);
    LeaveRequest::factory()->approved()->create(['member_id' => $this->intern->id, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20']);
    LeaveRequest::factory()->create(['member_id' => $this->mofAdmin->id]);

    $names = fn (User $user) => collect($this->actingAs($user)->get('/workspace/leave')->viewData('page')['props']['to_decide'])->pluck('member.name')->sort()->values()->all();

    expect($names($this->director->user))->toBe(['Engineer', 'Intern'])
        ->and($names($this->peer->user))->toBe([])
        ->and($names($this->intern->user))->toBe([])
        ->and($names($this->admin->user))->toBe(['Director', 'Engineer', 'Intern', 'Peer'])
        ->and($names($this->boss))->toBe(['Director', 'Engineer', 'Intern', 'MoF Admin', 'Peer'])
        ->and($names($this->mofAdmin->user))->toBe([]);
});

it('offers to record leave for the people below the user only', function () {
    $people = fn (User $user) => collect($this->actingAs($user)->get('/workspace/leave')->viewData('page')['props']['can_record_for'])->pluck('name')->all();

    expect($people($this->director->user))->toBe(['Engineer', 'Intern'])
        ->and($people($this->intern->user))->toBe([])
        ->and($people($this->admin->user))->toBe(['Director', 'Engineer', 'Executive Director', 'Intern', 'Peer'])
        ->and($people($this->boss))->toContain('MoF Admin', 'Admin');
});
