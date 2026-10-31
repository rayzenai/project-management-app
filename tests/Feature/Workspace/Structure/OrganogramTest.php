<?php

use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * The organogram: every active person of the organization sees it (names,
 * designations and lines, never emails); super-admins see any; nobody else.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'MoF']);
    $person = fn (array $attributes = []) => Member::factory()->withUser()->create(['organization_id' => $this->org->id, ...$attributes]);

    $this->edTitle = Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Executive Director', 'rank' => 1]);
    $this->directorTitle = Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Director', 'rank' => 10]);
    $this->unused = Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Analyst', 'rank' => 50]);

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = $person(['name' => 'Zara Admin', 'is_office_admin' => true]);
    $this->ed = $person(['name' => 'Kiran Timsina', 'designation_id' => $this->edTitle->id]);
    $this->director = $person(['name' => 'Sita Sharma', 'designation_id' => $this->directorTitle->id, 'reports_to_id' => $this->ed->id]);
    $this->engineer = $person(['name' => 'Anil Gurung', 'reports_to_id' => $this->director->id]);
    $this->away = $person(['name' => 'Hari Dahal', 'designation_id' => $this->directorTitle->id, 'reports_to_id' => $this->ed->id, 'is_active' => false]);
    // Reports to someone deactivated: shown at the top.
    $this->stranded = $person(['name' => 'Bikash Thapa', 'reports_to_id' => $this->away->id]);
    $this->outsider = Member::factory()->withUser()->create(['organization_id' => $this->otherOrg->id]);
});

it('shows an active member their organization, without emails', function () {
    $response = $this->actingAs($this->engineer->user)->get('/workspace/organogram');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Organogram/Index')
        ->where('organizations', [])
        ->where('organogram.organization', ['id' => $this->org->id, 'name' => 'SDOC'])
        ->where('organogram.can_manage', false)
        ->where('organogram.designations', [
            ['id' => $this->edTitle->id, 'organization_id' => $this->org->id, 'name' => 'Executive Director', 'rank' => 1, 'people_count' => 1],
            ['id' => $this->directorTitle->id, 'organization_id' => $this->org->id, 'name' => 'Director', 'rank' => 10, 'people_count' => 1],
            ['id' => $this->unused->id, 'organization_id' => $this->org->id, 'name' => 'Analyst', 'rank' => 50, 'people_count' => 0],
        ])
        ->where('organogram.people', [
            ['id' => $this->ed->id, 'name' => 'Kiran Timsina', 'designation_id' => $this->edTitle->id, 'designation' => 'Executive Director', 'reports_to_id' => null, 'is_office_admin' => false, 'direct_reports_count' => 1],
            ['id' => $this->director->id, 'name' => 'Sita Sharma', 'designation_id' => $this->directorTitle->id, 'designation' => 'Director', 'reports_to_id' => $this->ed->id, 'is_office_admin' => false, 'direct_reports_count' => 1],
            ['id' => $this->engineer->id, 'name' => 'Anil Gurung', 'designation_id' => null, 'designation' => null, 'reports_to_id' => $this->director->id, 'is_office_admin' => false, 'direct_reports_count' => 0],
            ['id' => $this->stranded->id, 'name' => 'Bikash Thapa', 'designation_id' => null, 'designation' => null, 'reports_to_id' => null, 'is_office_admin' => false, 'direct_reports_count' => 0],
            ['id' => $this->admin->id, 'name' => 'Zara Admin', 'designation_id' => null, 'designation' => null, 'reports_to_id' => null, 'is_office_admin' => true, 'direct_reports_count' => 0],
        ]));

    // The viewer's own email is in the shared props; nobody else's is.
    foreach ([$this->admin, $this->ed, $this->director, $this->stranded] as $other) {
        expect($response->getContent())->not->toContain($other->email);
    }
});

it('tells the organizational admin they may change it', function () {
    $this->actingAs($this->admin->user)
        ->get('/workspace/organogram')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organogram.can_manage', true));
});

it('refuses a login with no organization', function () {
    $this->actingAs(User::factory()->create())->get('/workspace/organogram')->assertForbidden();
});

it('ignores another organization picked by anyone but a super-admin', function () {
    $this->actingAs($this->engineer->user)
        ->get("/workspace/organogram?organization={$this->otherOrg->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organogram.organization.id', $this->org->id));
});

it('lets a super-admin pick an organization, and offers the choice first', function () {
    $this->actingAs($this->boss)
        ->get('/workspace/organogram')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organogram', null)
            ->where('organizations', [['id' => $this->otherOrg->id, 'name' => 'MoF'], ['id' => $this->org->id, 'name' => 'SDOC']]));

    $this->actingAs($this->boss)
        ->get("/workspace/organogram?organization={$this->org->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organogram.organization.id', $this->org->id)
            ->where('organogram.can_manage', true)
            ->has('organogram.people', 5));
});
