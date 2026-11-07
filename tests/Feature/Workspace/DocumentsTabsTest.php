<?php

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * The Documents page's four tabs (Recent, Personal, Shared with me,
 * Projects), what each row says about who can see it, and the old tab
 * names in links people already have
 * (docs/superpowers/specs/2026-11-07-documents-ui-design.md).
 */

beforeEach(function () {
    config([
        'project-management.super_admins' => ['boss@example.com'],
        'documents.enabled' => true,
        'documents.server_url' => 'https://docs.example',
        'documents.jwt_secret' => DOCS_SECRET,
    ]);
    Storage::fake('documents');

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->manager = docsPerson($this->organization, $this->project, 'leader');
    $this->member = docsPerson($this->organization, $this->project, 'member');
    $this->colleague = docsPerson($this->organization, null);
});

/** @return list<string> the titles on the page, sorted */
function docsTabTitles(TestResponse $response): array
{
    return collect($response->assertOk()->viewData('page')['props']['documents'])->pluck('title')->sort()->values()->all();
}

it('lists everything under Recent, the default', function () {
    docsMake($this->member, title: 'My notes');
    docsMake($this->manager, $this->project, 'Project plan');
    docsMake($this->colleague, title: 'Office circular', attributes: ['organization_access' => 'view']);

    $response = $this->actingAs($this->member)->get('/workspace/documents')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.show', 'recent')->where('total', 3));

    expect(docsTabTitles($response))->toBe(['My notes', 'Office circular', 'Project plan']);
});

it('keeps Personal to the viewer\'s own personal documents, not those they made in a project', function () {
    docsMake($this->member, title: 'My notes');
    docsMake($this->member, $this->project, 'Made in the project');
    docsShare(docsMake($this->colleague, title: 'Shared with me'), $this->member, 'view');

    expect(docsTabTitles($this->actingAs($this->member)->get('/workspace/documents?show=personal')))->toBe(['My notes']);
});

it('puts documents shared by name and office-wide ones the viewer reaches no other way under Shared with me', function () {
    $other = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Other']);
    docsPerson($this->organization, $other, 'leader');
    docsShare(docsMake($this->colleague, title: 'Shared by name'), $this->member, 'view');
    docsMake($this->colleague, title: 'Office circular', attributes: ['organization_access' => 'view']);
    docsMake($this->manager, $other, 'Other project, office-wide', ['organization_access' => 'edit']);
    docsShare(docsMake($this->manager, $this->project, 'In my project, shared by name'), $this->member, 'view');
    // Reached through the viewer's own project or as its owner: not "shared".
    docsMake($this->manager, $this->project, 'In my project, office-wide', ['organization_access' => 'view']);
    docsMake($this->member, title: 'My own, office-wide', attributes: ['organization_access' => 'view']);
    docsMake($this->colleague, title: 'Not shared');

    expect(docsTabTitles($this->actingAs($this->member)->get('/workspace/documents?show=shared')))->toBe([
        'In my project, shared by name',
        'Office circular',
        'Other project, office-wide',
        'Shared by name',
    ]);
});

it('shows a card per project whose documents the viewer may use under Projects, the newest edit first', function () {
    $quiet = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Archive']);
    $quiet->ensureTeam()->members()->attach(WorkspaceAccess::memberFor($this->member)?->id, ['role' => 'member']);
    $notMine = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Not mine']);
    docsMake(docsPerson($this->organization, $notMine, 'leader'), $notMine, 'Their plan');
    $this->travelTo(now()->subDay());
    docsMake($this->manager, $this->project, 'Old plan');
    $this->travelBack();
    docsMake($this->manager, $this->project, 'New plan');
    docsMake($this->manager, $this->project, 'Binned')->delete();

    $this->actingAs($this->member)->get('/workspace/documents?show=projects')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.show', 'projects')
            ->where('documents', [])
            ->where('next_cursor', null)
            ->has('project_cards', 2)
            ->where('project_cards.0.slug', $this->project->slug)
            ->where('project_cards.0.title', 'Tax Portal')
            ->where('project_cards.0.documents_count', 2)
            ->where('project_cards.0.last_edited_at', fn (string $at) => abs(now()->diffInSeconds($at)) < 5)
            ->where('project_cards.1.slug', $quiet->slug)
            ->where('project_cards.1.documents_count', 0)
            ->where('project_cards.1.last_edited_at', null));
});

it('searches the documents in projects instead of showing cards', function () {
    docsMake($this->manager, $this->project, 'Project plan');
    docsMake($this->member, title: 'Personal plan');

    $response = $this->actingAs($this->member)->get('/workspace/documents?show=projects&q=plan')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('project_cards', []));

    expect(docsTabTitles($response))->toBe(['Project plan']);
});

it('keeps the old tab names working in links', function (string $old, string $tab) {
    $this->actingAs($this->member)->get("/workspace/documents?show={$old}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.show', $tab));
})->with([
    'all' => ['all', 'recent'],
    'mine' => ['mine', 'personal'],
    'organization' => ['organization', 'shared'],
    'shared' => ['shared', 'shared'],
    'projects' => ['projects', 'projects'],
]);

it('refuses a tab that does not exist', function () {
    $this->actingAs($this->member)->get('/workspace/documents?show=everything')->assertSessionHasErrors('show');
});

it('counts the people a document is shared with, for whoever may share it, only while each share gives access', function () {
    $document = docsMake($this->member, title: 'My notes');
    docsShare($document, $this->colleague, 'view');
    docsShare($document, $this->manager, 'edit');
    $gone = docsPerson($this->organization, null);
    docsShare($document, $gone, 'view');
    docsUpdatePerson($gone, ['is_active' => false]);
    $deleted = docsPerson($this->organization, null);
    docsShare($document, $deleted, 'edit');
    WorkspaceAccess::memberFor($deleted)?->delete();
    WorkspaceAccess::flush();

    $row = fn (User $viewer) => collect($this->actingAs($viewer)->get('/workspace/documents')->viewData('page')['props']['documents'])->firstWhere('title', 'My notes');

    expect($row($this->member)['shares_count'])->toBe(2)
        ->and($row($this->colleague)['shares_count'])->toBeNull();
});

it('says whether the viewer may open a row\'s project', function () {
    $other = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Other']);
    docsShare(docsMake(docsPerson($this->organization, $other, 'leader'), $other, 'Shared plan'), $this->member, 'view');
    docsMake($this->manager, $this->project, 'Own plan');

    $rows = collect($this->actingAs($this->member)->get('/workspace/documents')->viewData('page')['props']['documents'])->keyBy('title');

    expect($rows['Own plan']['project']['can_open'])->toBeTrue()
        ->and($rows['Shared plan']['project']['can_open'])->toBeFalse();
});

it('lists project cards over the API, and old and new tab names', function () {
    Sanctum::actingAs($this->member);
    docsMake($this->manager, $this->project, 'Project plan');
    docsMake($this->member, title: 'My notes');

    $this->getJson('/api/v1/workspace/documents/projects')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $this->project->slug)
        ->assertJsonPath('data.0.documents_count', 1);
    $this->getJson('/api/v1/workspace/documents?show=personal')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'My notes');
    $this->getJson('/api/v1/workspace/documents?show=mine')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/workspace/documents?show=projects')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Project plan');
});

it('narrows a super-admin\'s project cards to the chosen organization', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $elsewhere = Organization::factory()->create();
    Project::factory()->create(['organization_id' => $elsewhere->id, 'title' => 'There']);

    $cards = fn () => collect($this->actingAs($boss)->get('/workspace/documents?show=projects')->viewData('page')['props']['project_cards'])->pluck('title')->all();
    expect($cards())->toContain('There', 'Tax Portal');

    $boss->forceFill(['workspace_organization_id' => $this->organization->id])->save();
    expect($cards())->toBe(['Tax Portal']);
});

it('says on the editor page that a document was just made, once', function () {
    $this->actingAs($this->member)->post('/workspace/documents', ['title' => 'Untitled document', 'type' => 'docx'])
        ->assertRedirect()
        ->assertSessionHas('workspace_flash.created_document');
});
