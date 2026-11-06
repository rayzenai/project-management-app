<?php

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

/*
 * The Documents page, personal documents, the editor at each person's level,
 * moving into a project (docs/superpowers/specs/2026-11-06-documents-workspace-design.md).
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
    $this->owner = docsPerson($this->organization, null);
    $this->colleague = docsPerson($this->organization, null);
});

// ---- The editor at each person's level ----

it('opens a Can view document read-only', function () {
    $document = docsMake($this->owner);
    docsAddFile($document);
    docsShare($document, $this->colleague, 'view');

    $response = $this->actingAs($this->colleague)->get("/workspace/documents/{$document->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Documents/Edit')
            ->where('document.access', 'view')
            ->where('document.can_share', false)
            ->where('project', null));
    $config = docsEditorConfig($response);

    expect($config['editorConfig']['mode'])->toBe('view')
        ->and($config['document']['permissions']['edit'])->toBeFalse()
        ->and($config['document']['permissions']['comment'])->toBeFalse()
        ->and($config['document']['permissions']['download'])->toBeTrue()
        ->and($config['editorConfig']['customization']['goback']['url'])->toEndWith('/workspace/documents');

    $this->actingAs($this->colleague)->patch("/workspace/documents/{$document->id}", ['title' => 'Mine now'])->assertForbidden();
    $this->actingAs($this->colleague)->delete("/workspace/documents/{$document->id}")->assertForbidden();

    expect($document->fresh()->title)->toBe('Notes')->and($document->fresh()->trashed())->toBeFalse();
});

it('opens the owner\'s personal document for editing, and nobody else\'s', function () {
    $document = docsMake($this->owner);
    docsAddFile($document);

    $response = $this->actingAs($this->owner)->get("/workspace/documents/{$document->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('document.access', 'edit')->where('document.can_share', true)->where('document.home', 'personal'));

    expect(docsEditorConfig($response)['editorConfig']['mode'])->toBe('edit');

    $this->actingAs($this->colleague)->get("/workspace/documents/{$document->id}")->assertForbidden();
    $this->actingAs($this->manager)->get("/workspace/documents/{$document->id}/versions")->assertForbidden();
});

it('opens a trashed frozen document for a super-admin only', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $proof = docsMake($this->owner, title: 'Proof');
    docsAddFile($proof);
    $proof->delete();
    docsUpdatePerson($this->owner, ['is_active' => false]);

    $response = $this->actingAs($boss)->get("/workspace/documents/{$proof->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('document.frozen', true)->where('document.access', 'view'));
    expect(docsEditorConfig($response)['editorConfig']['mode'])->toBe('view');

    $file = fn (int $id) => $this->withHeader('Authorization', 'Bearer '.docsToken(['url' => 'x'], 'download'))
        ->get(URL::temporarySignedRoute('documents.file', now()->addHour(), ['document' => $id, 'version' => 1]));
    auth()->logout();
    $file($proof->id)->assertOk();

    // Any other trashed document is out of the editor and its file route.
    $trashed = docsCreate($this->project, $this->member, 'Old draft');
    $trashed->delete();
    $this->actingAs($this->member)->get("/workspace/documents/{$trashed->id}")->assertNotFound();
    auth()->logout();
    $file($trashed->id)->assertNotFound();
});

it('sends old editor links to the new page', function () {
    $document = docsCreate($this->project, $this->member);

    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}/documents/{$document->id}")
        ->assertRedirect("/workspace/documents/{$document->id}");
});

it('lets someone off the team open a project document shared with them', function () {
    $document = docsCreate($this->project, $this->member);
    docsShare($document, $this->colleague, 'edit');

    $response = $this->actingAs($this->colleague)->get("/workspace/documents/{$document->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('document.access', 'edit')
            ->where('document.home', 'project')
            ->where('project.slug', $this->project->slug)
            ->where('project.can_open', false));
    $config = docsEditorConfig($response);

    expect($config['editorConfig']['mode'])->toBe('edit')
        // Not on the team: back to the Documents page, not a tab they can't open.
        ->and($config['editorConfig']['customization']['goback']['url'])->toEndWith('/workspace/documents');

    $back = docsEditorConfig($this->actingAs($this->member)->get("/workspace/documents/{$document->id}"))['editorConfig']['customization']['goback']['url'];
    expect($back)->toContain("/workspace/projects/{$this->project->slug}");
});
