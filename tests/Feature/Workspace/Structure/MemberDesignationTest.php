<?php

use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;

/*
 * A person's designation, chosen from the organogram: by the same people who
 * choose whom they report to, only one of their own organization's.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'MoF']);
    $person = fn (array $attributes = []) => Member::factory()->withUser()->create(['organization_id' => $this->org->id, ...$attributes]);

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = $person(['is_office_admin' => true]);
    $this->fellowAdmin = $person(['is_office_admin' => true]);
    $this->engineer = $person(['name' => 'Anil Gurung']);
    $this->colleague = $person();
    $this->otherAdmin = Member::factory()->withUser()->create(['organization_id' => $this->otherOrg->id, 'is_office_admin' => true]);

    $this->engineerTitle = Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Software Engineer']);
    $this->foreignTitle = Designation::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Under Secretary']);
});

it('lets the organizational admin give someone a designation, and clear it', function () {
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/designation", ['designation_id' => $this->engineerTitle->id])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Anil Gurung is now Software Engineer.']);

    expect($this->engineer->fresh()->designation_id)->toBe($this->engineerTitle->id);

    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/designation", ['designation_id' => null])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Anil Gurung has no designation now.']);

    expect($this->engineer->fresh()->designation_id)->toBeNull();
});

it('lets a super-admin choose too', function () {
    $this->actingAs($this->boss)
        ->put("/workspace/members/{$this->engineer->id}/designation", ['designation_id' => $this->engineerTitle->id])
        ->assertSessionHas('workspace_flash.success', true);

    expect($this->engineer->fresh()->designation_id)->toBe($this->engineerTitle->id);
});

it('refuses another organization\'s designation, an unknown one, and a missing field', function () {
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/designation", ['designation_id' => $this->foreignTitle->id])
        ->assertSessionHasErrors(['designation_id' => 'Choose one of the organization\'s designations.']);
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/designation", ['designation_id' => 999999])
        ->assertSessionHasErrors('designation_id');
    $this->actingAs($this->admin->user)
        ->put("/workspace/members/{$this->engineer->id}/designation", [])
        ->assertSessionHasErrors('designation_id');

    expect($this->engineer->fresh()->designation_id)->toBeNull();
});

it('forbids anyone who may not change the person', function () {
    $own = Member::factory()->linkedTo($this->boss)->create(['organization_id' => $this->org->id]);
    $loose = Member::factory()->create(['organization_id' => null]);

    $attempts = [
        [$this->colleague->user, $this->engineer],
        [$this->engineer->user, $this->engineer],
        [$this->otherAdmin->user, $this->engineer],
        [$this->admin->user, $this->fellowAdmin],
        [$this->admin->user, $own],
        [$this->boss, $loose],
    ];

    foreach ($attempts as [$user, $member]) {
        $this->actingAs($user)
            ->put("/workspace/members/{$member->id}/designation", ['designation_id' => $this->engineerTitle->id])
            ->assertForbidden();
    }

    expect(Member::query()->whereNotNull('designation_id')->count())->toBe(0);
});

it('answers the API with the person and their designation', function () {
    $this->actingAs($this->admin->user, 'sanctum')
        ->putJson("/api/v1/workspace/members/{$this->engineer->id}/designation", ['designation_id' => $this->engineerTitle->id])
        ->assertOk()
        ->assertJsonPath('message', 'Anil Gurung is now Software Engineer.')
        ->assertJsonPath('data.designation', ['id' => $this->engineerTitle->id, 'name' => 'Software Engineer']);

    $this->actingAs($this->admin->user, 'sanctum')
        ->putJson("/api/v1/workspace/members/{$this->engineer->id}/designation", ['designation_id' => $this->foreignTitle->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.designation_id', 'Choose one of the organization\'s designations.');
});
