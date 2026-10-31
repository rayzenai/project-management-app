<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Support\OrgChart;
use App\Support\WorkspaceAccess;

/*
 * Whom a person reports to: set by whoever administers their organization,
 * only to an active, current person of the same organization, never closing
 * a loop. ED -> director -> engineer; a peer of the director.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'MoF']);
    $person = fn (array $attributes = []) => Member::factory()->withUser()->create(['organization_id' => $this->org->id, ...$attributes]);

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = $person(['is_office_admin' => true]);
    $this->ed = $person(['name' => 'Kiran Timsina']);
    $this->director = $person(['name' => 'Sita Sharma', 'reports_to_id' => $this->ed->id]);
    $this->engineer = $person(['name' => 'Anil Gurung', 'reports_to_id' => $this->director->id]);
    $this->peer = $person(['name' => 'Rajesh Adhikari', 'reports_to_id' => $this->ed->id]);
    $this->outsider = Member::factory()->withUser()->create(['organization_id' => $this->otherOrg->id]);
    $this->otherAdmin = Member::factory()->withUser()->create(['organization_id' => $this->otherOrg->id, 'is_office_admin' => true]);
});

it('lets the organizational admin choose whom someone reports to', function () {
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $this->peer->id])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Anil Gurung now reports to Rajesh Adhikari.']);

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->peer->id)
        ->and(ProjectActivity::query()->count())->toBe(0);
});

it('lets a super-admin choose too', function () {
    $this->actingAs($this->boss)
        ->put("/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $this->ed->id])
        ->assertSessionHas('workspace_flash.success', true);

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->ed->id);
});

it('clears a higher authority', function () {
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->director->id}/reports-to", ['reports_to_id' => null])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Sita Sharma reports to no one now.']);

    expect($this->director->fresh()->reports_to_id)->toBeNull();
});

it('refuses a line that would close a loop', function () {
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->director->id}/reports-to", ['reports_to_id' => $this->engineer->id])
        ->assertSessionHasErrors(['reports_to_id' => 'Anil Gurung already reports to Sita Sharma, so Sita can\'t report to Anil.']);
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->ed->id}/reports-to", ['reports_to_id' => $this->engineer->id])
        ->assertSessionHasErrors('reports_to_id');
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->ed->id}/reports-to", ['reports_to_id' => $this->ed->id])
        ->assertSessionHasErrors('reports_to_id');

    expect($this->director->fresh()->reports_to_id)->toBe($this->ed->id)
        ->and($this->ed->fresh()->reports_to_id)->toBeNull();
});

it('refuses someone of another organization, or someone deleted', function () {
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $this->outsider->id])
        ->assertSessionHasErrors(['reports_to_id' => 'Choose someone in the same organization.']);

    $gone = Member::factory()->create(['organization_id' => $this->org->id]);
    $gone->delete();

    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $gone->id])
        ->assertSessionHasErrors('reports_to_id');

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->director->id);
});

it('refuses a deactivated higher authority', function () {
    $this->peer->update(['is_active' => false]);

    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $this->peer->id])
        ->assertSessionHasErrors(['reports_to_id' => 'Rajesh Adhikari is deactivated. Choose someone active.']);

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->director->id);
});

it('refuses anyone who does not administer the organization', function (string $who) {
    $user = match ($who) {
        'the person themselves' => $this->engineer->user,
        'their higher authority' => $this->director->user,
        'the head of the organization' => $this->ed->user,
        "another organization's admin" => $this->otherAdmin->user,
        'an outsider' => $this->outsider->user,
    };

    $this->actingAs($user)
        ->put("/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $this->peer->id])
        ->assertForbidden();

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->director->id);
})->with(['the person themselves', 'their higher authority', 'the head of the organization', "another organization's admin", 'an outsider']);

it('refuses a deactivated organizational admin', function () {
    $this->admin->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canManageStructure($this->admin->user, $this->org->id))->toBeFalse();

    // Signed out on the next request, and nothing changes.
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $this->peer->id])
        ->assertRedirect('/workspace/login');

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->director->id);
});

it('needs the field, even when it is null', function () {
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/reports-to", [])
        ->assertSessionHasErrors('reports_to_id');

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->director->id);
});

it('never lets an organizational admin put someone over a fellow admin', function () {
    $coAdmin = Member::factory()->withUser()->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->actingAs($this->admin->user)
        ->putJson("/api/v1/workspace/members/{$coAdmin->id}/reports-to", ['reports_to_id' => $this->engineer->id])
        ->assertForbidden();

    expect($coAdmin->fresh()->reports_to_id)->toBeNull();

    $this->actingAs($this->boss)
        ->putJson("/api/v1/workspace/members/{$coAdmin->id}/reports-to", ['reports_to_id' => $this->ed->id])
        ->assertOk();
});

it('walks up past nobody deleted', function () {
    Member::query()->whereKey($this->director->id)->delete(); // a bulk delete skips the observer
    OrgChart::flush();

    expect(OrgChart::ancestorIds($this->engineer))->toBe([]);
});
