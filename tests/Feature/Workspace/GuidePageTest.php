<?php

use App\Models\GitConnection;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows the guide to anyone signed in', function () {
    $this->actingAs(User::factory()->withMember()->create())
        ->get('/workspace/guide')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Guide'));
});

it('sends visitors who are not signed in to the login page', function () {
    $this->get('/workspace/guide')->assertRedirect();
});

it("names the forges the reader may use, their own organization's first, and their forge username", function () {
    $sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $mof = Organization::factory()->create(['name' => 'MoF']);
    GitConnection::query()->create(['organization_id' => $mof->id, 'base_url' => 'https://git.mof.example/', 'owner' => 'mof', 'token' => 'token-never-shown']);
    GitConnection::query()->create(['organization_id' => $sdoc->id, 'base_url' => 'https://git.sdoc.example', 'owner' => 'sdoc', 'token' => 'token-never-shown']);
    $user = User::factory()->create(['git_username' => 'asha']);
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $sdoc->id]);
    // On a cross-organization team of MoF's: that forge is theirs too.
    Project::factory()->create(['organization_id' => $mof->id])->ensureTeam()->members()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($user)->get('/workspace/guide')->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Guide')
        ->where('forges', [
            ['organization' => 'SDOC', 'url' => 'https://git.sdoc.example', 'owner' => 'sdoc'],
            ['organization' => 'MoF', 'url' => 'https://git.mof.example', 'owner' => 'mof'],
        ])
        ->where('gitUsername', 'asha'));
    expect($response->getContent())->not->toContain('token-never-shown');
});

it('names no forge to someone whose office has no git', function () {
    $user = User::factory()->withMember()->create();

    $this->actingAs($user)->get('/workspace/guide')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('forges', [])->where('gitUsername', null));
});
