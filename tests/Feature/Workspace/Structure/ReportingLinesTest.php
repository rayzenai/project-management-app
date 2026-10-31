<?php

use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrgChart;
use Illuminate\Support\Facades\Mail;

/*
 * Lines stay whole when people leave: the people who reported to someone
 * deleted, or moved to another organization, move up to that person's own
 * higher authority. Deactivation keeps every line.
 * ED -> director -> (engineer, analyst) ; engineer -> intern.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    Mail::fake();

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'MoF']);
    $person = fn (array $attributes = []) => Member::factory()->withUser()->create(['organization_id' => $this->org->id, ...$attributes]);

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = $person(['is_office_admin' => true]);
    $this->ed = $person();
    $this->director = $person(['reports_to_id' => $this->ed->id]);
    $this->engineer = $person(['reports_to_id' => $this->director->id]);
    $this->analyst = $person(['reports_to_id' => $this->director->id]);
    $this->intern = $person(['reports_to_id' => $this->engineer->id]);
});

it('moves the people of someone deleted up to their higher authority', function () {
    $this->actingAs($this->admin->user)->delete("/workspace/members/{$this->director->id}")->assertRedirect();

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->ed->id)
        ->and($this->analyst->fresh()->reports_to_id)->toBe($this->ed->id)
        ->and($this->intern->fresh()->reports_to_id)->toBe($this->engineer->id)
        ->and(OrgChart::descendantIds($this->ed))->toEqualCanonicalizing([$this->engineer->id, $this->analyst->id, $this->intern->id]);
});

it('leaves the people of someone at the top with no one above', function () {
    $this->ed->delete();

    expect($this->director->fresh()->reports_to_id)->toBeNull();
});

it('moves people up on a hard delete as well', function () {
    $this->director->forceDelete();

    expect($this->engineer->fresh()->reports_to_id)->toBe($this->ed->id)
        ->and($this->analyst->fresh()->reports_to_id)->toBe($this->ed->id);
});

it('moves people up when someone moves to another organization, who then reports to no one', function () {
    $designation = Designation::factory()->create(['organization_id' => $this->org->id]);
    $this->director->update(['designation_id' => $designation->id]);

    $this->actingAs($this->boss)
        ->patch("/workspace/members/{$this->director->id}", ['organization_id' => $this->otherOrg->id])
        ->assertSessionHasNoErrors();

    $director = $this->director->fresh();

    expect($director->organization_id)->toBe($this->otherOrg->id)
        ->and($director->reports_to_id)->toBeNull()
        ->and($director->designation_id)->toBeNull()
        ->and($this->engineer->fresh()->reports_to_id)->toBe($this->ed->id)
        ->and($this->analyst->fresh()->reports_to_id)->toBe($this->ed->id);
});

it('keeps every line when someone is deactivated', function () {
    $this->actingAs($this->admin->user)
        ->patch("/workspace/members/{$this->director->id}", ['is_active' => false])
        ->assertSessionHasNoErrors();

    expect($this->director->fresh()->reports_to_id)->toBe($this->ed->id)
        ->and($this->engineer->fresh()->reports_to_id)->toBe($this->director->id)
        ->and($this->analyst->fresh()->reports_to_id)->toBe($this->director->id);
});
