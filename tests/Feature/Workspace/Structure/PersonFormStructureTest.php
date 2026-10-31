<?php

use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/*
 * The person form's designation and "reports to": set by whoever may change
 * the organization's structure, only to the organization's own designations
 * and people; a refused "reports to" undoes the whole save.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    Mail::fake();

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'MoF']);
    $person = fn (array $attributes = []) => Member::factory()->withUser()->create(['organization_id' => $this->org->id, ...$attributes]);

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = $person(['is_office_admin' => true]);
    $this->ed = $person(['name' => 'Kiran Timsina']);
    $this->director = $person(['name' => 'Sita Sharma', 'reports_to_id' => $this->ed->id]);
    $this->engineer = $person(['name' => 'Anil Gurung', 'reports_to_id' => $this->director->id]);

    $this->engineerTitle = Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Software Engineer']);
    $this->foreignTitle = Designation::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Under Secretary']);
    $this->outsider = Member::factory()->create(['organization_id' => $this->otherOrg->id]);
});

it('lets the organizational admin give a new person a designation and a higher authority', function () {
    $this->actingAs($this->admin->user)
        ->post('/workspace/members', [
            'name' => 'Pratima KC',
            'email' => 'pratima@example.com',
            'password' => 'secret-password',
            'designation_id' => $this->engineerTitle->id,
            'reports_to_id' => $this->director->id,
        ])
        ->assertSessionHasNoErrors();

    $member = Member::query()->where('email', 'pratima@example.com')->firstOrFail();

    expect($member->designation_id)->toBe($this->engineerTitle->id)
        ->and($member->reports_to_id)->toBe($this->director->id);
});

it('lets the organizational admin change them on the person form', function () {
    $this->actingAs($this->admin->user)
        ->patch("/workspace/members/{$this->engineer->id}", [
            'designation_id' => $this->engineerTitle->id,
            'reports_to_id' => $this->ed->id,
        ])
        ->assertSessionHasNoErrors();

    expect($this->engineer->fresh()->only(['designation_id', 'reports_to_id']))
        ->toEqual(['designation_id' => $this->engineerTitle->id, 'reports_to_id' => $this->ed->id]);
});

it('clears them when sent empty and keeps them when left out', function () {
    $this->engineer->update(['designation_id' => $this->engineerTitle->id]);

    $this->actingAs($this->admin->user)
        ->patch("/workspace/members/{$this->engineer->id}", ['title' => 'Backend'])
        ->assertSessionHasNoErrors();

    expect($this->engineer->fresh()->only(['designation_id', 'reports_to_id']))
        ->toEqual(['designation_id' => $this->engineerTitle->id, 'reports_to_id' => $this->director->id]);

    $this->actingAs($this->admin->user)
        ->patch("/workspace/members/{$this->engineer->id}", ['designation_id' => null, 'reports_to_id' => null])
        ->assertSessionHasNoErrors();

    expect($this->engineer->fresh()->only(['designation_id', 'reports_to_id']))
        ->toEqual(['designation_id' => null, 'reports_to_id' => null]);
});

it("refuses another organization's designation or person", function () {
    $this->actingAs($this->admin->user)
        ->patch("/workspace/members/{$this->engineer->id}", ['designation_id' => $this->foreignTitle->id])
        ->assertSessionHasErrors('designation_id');
    $this->actingAs($this->admin->user)
        ->patch("/workspace/members/{$this->engineer->id}", ['reports_to_id' => $this->outsider->id])
        ->assertSessionHasErrors('reports_to_id');
    $this->actingAs($this->admin->user)
        ->post('/workspace/members', [
            'name' => 'Pratima KC',
            'email' => 'pratima@example.com',
            'password' => 'secret-password',
            'designation_id' => $this->foreignTitle->id,
        ])
        ->assertSessionHasErrors('designation_id');

    expect($this->engineer->fresh()->only(['designation_id', 'reports_to_id']))
        ->toEqual(['designation_id' => null, 'reports_to_id' => $this->director->id])
        ->and(Member::query()->where('email', 'pratima@example.com')->exists())->toBeFalse();
});

it('undoes the whole save when the higher authority is refused', function () {
    $this->actingAs($this->admin->user)
        ->patch("/workspace/members/{$this->director->id}", [
            'name' => 'Sita S. Sharma',
            'designation_id' => $this->engineerTitle->id,
            'reports_to_id' => $this->engineer->id,
        ])
        ->assertSessionHasErrors('reports_to_id');

    expect($this->director->fresh()->only(['name', 'designation_id', 'reports_to_id']))
        ->toEqual(['name' => 'Sita Sharma', 'designation_id' => null, 'reports_to_id' => $this->ed->id]);
});

it('drops the fields silently for someone who may not change the structure', function () {
    // A super-admin's own member belongs to no organization, so it has no
    // structure for anyone to change.
    $own = Member::factory()->linkedTo($this->boss)->create(['organization_id' => null]);

    $this->actingAs($this->boss)
        ->patch("/workspace/members/{$own->id}", [
            'title' => 'Executive Director',
            'designation_id' => $this->engineerTitle->id,
            'reports_to_id' => $this->ed->id,
        ])
        ->assertSessionHasNoErrors();

    expect($own->fresh()->only(['title', 'designation_id', 'reports_to_id']))
        ->toEqual(['title' => 'Executive Director', 'designation_id' => null, 'reports_to_id' => null]);
});

it('keeps a designation of the new organization given in the same move', function () {
    $this->actingAs($this->boss)
        ->patch("/workspace/members/{$this->engineer->id}", [
            'organization_id' => $this->otherOrg->id,
            'designation_id' => $this->foreignTitle->id,
            'reports_to_id' => $this->outsider->id,
        ])
        ->assertSessionHasNoErrors();

    expect($this->engineer->fresh()->only(['organization_id', 'designation_id', 'reports_to_id']))
        ->toEqual(['organization_id' => $this->otherOrg->id, 'designation_id' => $this->foreignTitle->id, 'reports_to_id' => $this->outsider->id]);
});

it('shows designations and the fields on the People page to whoever may change the structure', function () {
    $this->engineer->update(['designation_id' => $this->engineerTitle->id]);

    $this->actingAs($this->admin->user)
        ->get('/workspace/team')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSetStructure', true)
            ->where('designationOptions', [['id' => $this->engineerTitle->id, 'organization_id' => $this->org->id, 'name' => 'Software Engineer']])
            ->where('members', fn ($members) => collect($members)->firstWhere('id', $this->engineer->id)['designation'] === ['id' => $this->engineerTitle->id, 'name' => 'Software Engineer']
                && collect($members)->firstWhere('id', $this->engineer->id)['reports_to_id'] === $this->director->id));

    $this->actingAs($this->engineer->user)
        ->get('/workspace/team')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSetStructure', false)
            ->where('designationOptions', []));
});
