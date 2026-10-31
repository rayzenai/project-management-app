<?php

use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\WorkspaceAccess;

/*
 * An organization's designations: whoever administers it adds, renames,
 * re-ranks and removes them; nobody else. Names are unique in the
 * organization whatever their case. Removing one leaves its people with
 * no designation.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'MoF']);

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = Member::factory()->withUser()->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);
    $this->staff = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $this->otherAdmin = Member::factory()->withUser()->create(['organization_id' => $this->otherOrg->id, 'is_office_admin' => true]);

    $this->director = Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Director', 'rank' => 10]);
});

it('lets the organizational admin add a designation', function () {
    $this->actingAs($this->admin->user)
        ->post("/workspace/organizations/{$this->org->id}/designations", ['name' => 'Software Engineer', 'rank' => 40])
        ->assertRedirect()
        ->assertSessionHas('workspace_flash.success', true);

    expect(Designation::query()->where('organization_id', $this->org->id)->where('name', 'Software Engineer')->value('rank'))->toBe(40);
});

it('gives a new designation rank 100 when none is sent', function () {
    $this->actingAs($this->admin->user)
        ->post("/workspace/organizations/{$this->org->id}/designations", ['name' => 'Office Assistant']);

    expect(Designation::query()->where('name', 'Office Assistant')->value('rank'))->toBe(100);
});

it('lets a super-admin add a designation to any organization', function () {
    $this->actingAs($this->boss)
        ->post("/workspace/organizations/{$this->otherOrg->id}/designations", ['name' => 'Joint Secretary'])
        ->assertSessionHas('workspace_flash.success', true);

    expect(Designation::query()->where('organization_id', $this->otherOrg->id)->where('name', 'Joint Secretary')->exists())->toBeTrue();
});

it('refuses designations to an ordinary member of the organization', function () {
    $this->actingAs($this->staff->user)
        ->post("/workspace/organizations/{$this->org->id}/designations", ['name' => 'Chief'])
        ->assertForbidden();
    $this->actingAs($this->staff->user)
        ->patch("/workspace/designations/{$this->director->id}", ['name' => 'Boss'])
        ->assertForbidden();
    $this->actingAs($this->staff->user)
        ->delete("/workspace/designations/{$this->director->id}")
        ->assertForbidden();

    expect(Designation::query()->count())->toBe(1)
        ->and($this->director->fresh()->name)->toBe('Director');
});

it("refuses another organization's admin", function () {
    $this->actingAs($this->otherAdmin->user)
        ->post("/workspace/organizations/{$this->org->id}/designations", ['name' => 'Chief'])
        ->assertForbidden();
    $this->actingAs($this->otherAdmin->user)
        ->patch("/workspace/designations/{$this->director->id}", ['name' => 'Boss'])
        ->assertForbidden();
    $this->actingAs($this->otherAdmin->user)
        ->delete("/workspace/designations/{$this->director->id}")
        ->assertForbidden();

    expect($this->director->fresh())->not->toBeNull();
});

it('refuses a deactivated organizational admin', function () {
    $this->admin->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canManageStructure($this->admin->user, $this->org->id))->toBeFalse();

    // Signed out on the next request, and nothing is added.
    $this->actingAs($this->admin->user)
        ->post("/workspace/organizations/{$this->org->id}/designations", ['name' => 'Chief'])
        ->assertRedirect('/workspace/login');

    expect(Designation::query()->where('name', 'Chief')->exists())->toBeFalse();
});

it('refuses a name the organization already has, whatever its case', function () {
    $this->actingAs($this->admin->user)
        ->post("/workspace/organizations/{$this->org->id}/designations", ['name' => 'director'])
        ->assertSessionHasErrors(['name' => 'There is already a designation called director.']);

    expect(Designation::query()->where('organization_id', $this->org->id)->count())->toBe(1);
});

it('allows the same name in another organization', function () {
    $this->actingAs($this->otherAdmin->user)
        ->post("/workspace/organizations/{$this->otherOrg->id}/designations", ['name' => 'Director'])
        ->assertSessionHas('workspace_flash.success', true);

    expect(Designation::query()->where('name', 'Director')->count())->toBe(2);
});

it('validates the name and rank', function () {
    $this->actingAs($this->admin->user)
        ->post("/workspace/organizations/{$this->org->id}/designations", ['name' => '', 'rank' => -1])
        ->assertSessionHasErrors(['name', 'rank']);
    $this->actingAs($this->admin->user)
        ->post("/workspace/organizations/{$this->org->id}/designations", ['name' => str_repeat('a', 121)])
        ->assertSessionHasErrors(['name']);
});

it('renames and re-ranks a designation', function () {
    $this->actingAs($this->admin->user)
        ->patch("/workspace/designations/{$this->director->id}", ['name' => 'Director, Development', 'rank' => 5])
        ->assertSessionHas('workspace_flash.success', true);

    expect($this->director->fresh()->only(['name', 'rank']))->toEqual(['name' => 'Director, Development', 'rank' => 5]);
});

it('keeps a designation its own name in another case, and refuses another one taken', function () {
    Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Engineer']);

    $this->actingAs($this->admin->user)
        ->patch("/workspace/designations/{$this->director->id}", ['name' => 'DIRECTOR'])
        ->assertSessionHas('workspace_flash.success', true);
    $this->actingAs($this->admin->user)
        ->patch("/workspace/designations/{$this->director->id}", ['name' => 'engineer'])
        ->assertSessionHasErrors('name');

    expect($this->director->fresh()->name)->toBe('DIRECTOR');
});

it('removes a designation and leaves its people without one', function () {
    $this->staff->update(['designation_id' => $this->director->id]);

    $this->actingAs($this->admin->user)
        ->delete("/workspace/designations/{$this->director->id}")
        ->assertSessionHas('workspace_flash.success', true);

    expect(Designation::query()->find($this->director->id))->toBeNull()
        ->and($this->staff->fresh()->designation_id)->toBeNull()
        ->and($this->staff->fresh()->trashed())->toBeFalse();
});
