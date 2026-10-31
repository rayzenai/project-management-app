<?php

use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * The structure's API twins: the organogram, designations and "reports
 * to", over the same requests, services, query and resources as the web.
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
    $this->outsider = Member::factory()->withUser()->create(['organization_id' => $this->otherOrg->id]);
    $this->title = Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Director', 'rank' => 10]);
});

it('returns the organogram to a member of the organization, without emails', function () {
    Sanctum::actingAs($this->engineer->user, ['*']);

    $response = $this->getJson("/api/v1/workspace/organizations/{$this->org->id}/organogram")
        ->assertOk()
        ->assertJsonPath('data.organization.name', 'SDOC')
        ->assertJsonPath('data.can_manage', false)
        ->assertJsonCount(4, 'data.people')
        ->assertJsonPath('data.designations.0.name', 'Director');

    expect($response->getContent())->not->toContain('@');
});

it("refuses another organization's organogram to an outsider", function () {
    Sanctum::actingAs($this->outsider->user, ['*']);

    $this->getJson("/api/v1/workspace/organizations/{$this->org->id}/organogram")->assertForbidden();
});

it('returns any organogram to a super-admin', function () {
    Sanctum::actingAs($this->boss, ['*']);

    $this->getJson("/api/v1/workspace/organizations/{$this->otherOrg->id}/organogram")
        ->assertOk()
        ->assertJsonPath('data.can_manage', true)
        ->assertJsonCount(1, 'data.people');
});

it('adds, changes and removes a designation for the organizational admin', function () {
    Sanctum::actingAs($this->admin->user, ['*']);

    $id = $this->postJson("/api/v1/workspace/organizations/{$this->org->id}/designations", ['name' => 'Software Engineer', 'rank' => 40])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Software Engineer')
        ->assertJsonPath('data.rank', 40)
        ->json('data.id');

    $this->patchJson("/api/v1/workspace/designations/{$id}", ['name' => 'Senior Software Engineer', 'rank' => 30])
        ->assertOk()
        ->assertJsonPath('data.name', 'Senior Software Engineer')
        ->assertJsonPath('data.rank', 30);

    $this->postJson("/api/v1/workspace/organizations/{$this->org->id}/designations", ['name' => 'DIRECTOR'])
        ->assertStatus(422)
        ->assertJsonPath('errors.name', 'There is already a designation called DIRECTOR.');

    $this->deleteJson("/api/v1/workspace/designations/{$id}")->assertOk();

    expect(Designation::query()->find($id))->toBeNull();
});

it('refuses designations to a member and to an outsider', function () {
    Sanctum::actingAs($this->engineer->user, ['*']);
    $this->postJson("/api/v1/workspace/organizations/{$this->org->id}/designations", ['name' => 'Chief'])->assertForbidden();
    $this->patchJson("/api/v1/workspace/designations/{$this->title->id}", ['name' => 'Chief'])->assertForbidden();

    Sanctum::actingAs($this->outsider->user, ['*']);
    $this->deleteJson("/api/v1/workspace/designations/{$this->title->id}")->assertForbidden();

    expect($this->title->fresh()->name)->toBe('Director');
});

it('sets and clears whom someone reports to', function () {
    Sanctum::actingAs($this->admin->user, ['*']);

    $this->putJson("/api/v1/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $this->ed->id])
        ->assertOk()
        ->assertJsonPath('message', 'Anil Gurung now reports to Kiran Timsina.')
        ->assertJsonPath('data.reports_to_id', $this->ed->id);

    $this->putJson("/api/v1/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => null])
        ->assertOk()
        ->assertJsonPath('data.reports_to_id', null);
});

it('answers a loop or another organization with a 422', function () {
    Sanctum::actingAs($this->admin->user, ['*']);

    $this->putJson("/api/v1/workspace/members/{$this->ed->id}/reports-to", ['reports_to_id' => $this->engineer->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.reports_to_id', "Anil Gurung already reports to Kiran Timsina, so Kiran can't report to Anil.");
    $this->putJson("/api/v1/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => $this->outsider->id])
        ->assertStatus(422);

    expect($this->ed->fresh()->reports_to_id)->toBeNull();
});

it('refuses "reports to" to anyone who does not administer the organization', function () {
    Sanctum::actingAs($this->director->user, ['*']);

    $this->putJson("/api/v1/workspace/members/{$this->engineer->id}/reports-to", ['reports_to_id' => null])->assertForbidden();

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->director->id);
});

it('takes designation and reports to on the person form', function () {
    Sanctum::actingAs($this->admin->user, ['*']);

    $this->patchJson("/api/v1/workspace/members/{$this->engineer->id}", ['designation_id' => $this->title->id, 'reports_to_id' => $this->ed->id])
        ->assertOk()
        ->assertJsonPath('data.designation', ['id' => $this->title->id, 'name' => 'Director'])
        ->assertJsonPath('data.reports_to_id', $this->ed->id);
});
