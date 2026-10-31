<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

function organizationsBoss(): User
{
    return User::factory()->create(['email' => 'boss@example.com']);
}

it('lists organizations with their counts and office admins for a super-admin', function () {
    $organization = Organization::factory()->create(['name' => 'Ministry of Health and Food Safety']);
    Member::factory()->create(['organization_id' => $organization->id, 'is_office_admin' => true, 'name' => 'Chief']);
    Member::factory()->create(['organization_id' => $organization->id]);
    Team::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs(organizationsBoss())->get('/workspace/organizations')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Organizations/Index')
            ->where('organizations.0.name', 'Ministry of Health and Food Safety')
            ->where('organizations.0.members_count', 2)
            ->where('organizations.0.teams_count', 1)
            ->where('organizations.0.office_admins.0.name', 'Chief'));
});

it('suggests the configured forge address for turning git on', function () {
    config(['project-management.git.default_forge_url' => 'https://git.example.gov.np']);

    $this->actingAs(organizationsBoss())->get('/workspace/organizations')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Organizations/Index')
            ->where('defaultForgeUrl', 'https://git.example.gov.np'));
});

it('creates and renames organizations, refusing duplicate names', function () {
    $this->actingAs(organizationsBoss());

    $this->post('/workspace/organizations', ['name' => 'MOLCFAGA'])->assertSessionHasNoErrors();
    $organization = Organization::query()->where('name', 'MOLCFAGA')->firstOrFail();

    $this->post('/workspace/organizations', ['name' => 'MOLCFAGA'])->assertSessionHasErrors('name');

    $this->patch("/workspace/organizations/{$organization->id}", ['name' => 'Ministry of Land Management'])
        ->assertSessionHasNoErrors();

    expect($organization->fresh()->name)->toBe('Ministry of Land Management')
        ->and($organization->slug)->toBe('molcfaga');
});

it('deletes an organization only once nothing belongs to it', function () {
    $this->actingAs(organizationsBoss());
    $busy = Organization::factory()->create();
    Project::factory()->create(['organization_id' => $busy->id]);
    $empty = Organization::factory()->create();

    $this->delete("/workspace/organizations/{$busy->id}")->assertSessionHasErrors();
    $this->delete("/workspace/organizations/{$empty->id}")->assertSessionHasNoErrors();

    expect(Organization::query()->find($busy->id))->not->toBeNull()
        ->and(Organization::query()->find($empty->id))->toBeNull();
});

it('keeps organizations a super-admin matter, for office admins too', function () {
    $organization = Organization::factory()->create(['name' => 'Original']);
    $admin = User::factory()->create();
    Member::factory()->linkedTo($admin)->create(['organization_id' => $organization->id, 'is_office_admin' => true]);

    $this->actingAs($admin);
    $this->get('/workspace/organizations')->assertForbidden();
    $this->post('/workspace/organizations', ['name' => 'Sneaky'])->assertForbidden();
    $this->patch("/workspace/organizations/{$organization->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->delete("/workspace/organizations/{$organization->id}")->assertForbidden();

    expect($organization->fresh()->name)->toBe('Original')
        ->and(Organization::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('offers the same over the API', function () {
    Sanctum::actingAs(organizationsBoss(), ['*']);

    $id = $this->postJson('/api/v1/workspace/organizations', ['name' => 'OPMCM'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'OPMCM')
        ->json('data.id');

    $this->getJson('/api/v1/workspace/organizations')->assertOk()->assertJsonPath('organizations.0.id', $id);
    $this->deleteJson("/api/v1/workspace/organizations/{$id}")->assertOk();

    Sanctum::actingAs(User::factory()->withMember()->create(), ['*']);
    $this->getJson('/api/v1/workspace/organizations')->assertForbidden();
});

it('moves existing data into one organization named after the app, leaving super-admins out', function () {
    config(['app.name' => 'SDOC']);
    // The stable-key migration indexes organization_id, so it steps aside while the column goes.
    $keys = require database_path('migrations/2026_10_30_000001_give_projects_a_stable_key.php');
    $keys->down();
    // So does the workspace switch, whose column on users points at organizations.
    $switch = require database_path('migrations/2026_10_31_210000_add_workspace_organization_to_users.php');
    $switch->down();
    $migration = require database_path('migrations/2026_10_28_100000_create_organizations_table.php');
    $migration->down();

    $boss = organizationsBoss();
    $staff = User::factory()->create();
    $now = now();
    DB::table('members')->insert([
        ['user_id' => $boss->id, 'name' => 'Boss', 'email' => $boss->email, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => $staff->id, 'name' => 'Staff', 'email' => $staff->email, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => null, 'name' => 'No Login', 'email' => 'nologin@example.com', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('teams')->insert(['name' => 'Testers', 'slug' => 'testers', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('projects')->insert(['title' => 'Plan', 'slug' => 'plan', 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);

    $migration->up();
    $keys->up();
    $switch->up();

    $organization = Organization::query()->sole();

    expect($organization->name)->toBe('SDOC')
        ->and($organization->slug)->toBe('sdoc')
        ->and(Member::query()->where('name', 'Boss')->value('organization_id'))->toBeNull()
        ->and(Member::query()->where('name', 'Staff')->value('organization_id'))->toBe($organization->id)
        ->and(Member::query()->where('name', 'No Login')->value('organization_id'))->toBe($organization->id)
        ->and(Team::query()->where('slug', 'testers')->value('organization_id'))->toBe($organization->id)
        ->and(Project::query()->where('slug', 'plan')->value('organization_id'))->toBe($organization->id);
});

it('creates no organization when there is nothing to move', function () {
    // The stable-key migration indexes organization_id, so it steps aside while the column goes.
    $keys = require database_path('migrations/2026_10_30_000001_give_projects_a_stable_key.php');
    $keys->down();
    $migration = require database_path('migrations/2026_10_28_100000_create_organizations_table.php');
    $migration->down();
    $migration->up();
    $keys->up();

    expect(Organization::query()->count())->toBe(0);
});
