<?php

use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

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

// ---- The Documents page ----

/** The page's rows, by title, in order. */
function docsPageTitles(TestResponse $response): array
{
    return array_column($response->viewData('page')['props']['documents'], 'title');
}

it('lists every document the viewer may open, newest first', function () {
    $outsider = docsPerson(Organization::factory()->create(), null);
    $this->travelTo(now()->subDays(5));
    docsMake($this->member, title: 'My notes');
    $this->travelBack();
    $this->travelTo(now()->subDays(4));
    docsShare(docsMake($this->colleague, title: 'Shared with me'), $this->member, 'view');
    $this->travelBack();
    $this->travelTo(now()->subDays(3));
    docsMake($this->colleague, title: 'Office circular', attributes: ['organization_access' => 'view']);
    $this->travelBack();
    $this->travelTo(now()->subDays(2));
    docsMake($this->manager, $this->project, 'Project plan');
    $this->travelBack();
    docsMake($this->colleague, title: 'Not mine');
    docsMake($outsider, title: 'Elsewhere', attributes: ['organization_access' => 'edit']);

    $response = $this->actingAs($this->member)->get('/workspace/documents')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Documents/Index')
            ->where('filters.show', 'all')
            ->where('can_create_personal', true)
            ->where('organization.name', 'SDOC')
            ->where('projects.0.slug', $this->project->slug)
            ->where('next_cursor', null));

    expect(docsPageTitles($response))->toBe(['Project plan', 'Office circular', 'Shared with me', 'My notes']);
});

it('filters by show, project, type and title', function () {
    $other = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Other']);
    $other->ensureTeam()->members()->attach(WorkspaceAccess::memberFor($this->member)?->id, ['role' => 'member']);
    docsMake($this->member, title: 'My notes');
    docsShare(docsMake($this->colleague, title: 'Shared with me'), $this->member, 'view');
    docsMake($this->colleague, title: 'Office circular', attributes: ['organization_access' => 'view']);
    docsMake($this->manager, $this->project, 'Project plan');
    docsMake($this->manager, $other, 'Other plan', ['file_type' => 'xlsx']);

    $titles = fn (string $query) => collect(docsPageTitles($this->actingAs($this->member)->get("/workspace/documents?{$query}")))->sort()->values()->all();

    expect($titles('show=mine'))->toBe(['My notes'])
        ->and($titles('show=shared'))->toBe(['Shared with me'])
        ->and($titles('show=organization'))->toBe(['Office circular'])
        ->and($titles('show=projects'))->toBe(['Other plan', 'Project plan'])
        ->and($titles("show=projects&project={$other->slug}"))->toBe(['Other plan'])
        ->and($titles('type=xlsx'))->toBe(['Other plan'])
        ->and($titles('q=PLAN'))->toBe(['Other plan', 'Project plan']);

    $this->actingAs($this->member)->get('/workspace/documents?show=everything')->assertSessionHasErrors('show');
});

it('pages by updated_at then id', function () {
    $this->travelTo(now()->subHour());
    foreach (range(1, 51) as $i) {
        docsMake($this->member, title: "Note {$i}");
    }
    $this->travelBack();

    $first = $this->actingAs($this->member)->get('/workspace/documents');
    $cursor = $first->viewData('page')['props']['next_cursor'];
    $rest = $this->actingAs($this->member)->getJson('/workspace/documents/list?cursor='.urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonPath('next_cursor', null);

    $ids = [...array_column($first->viewData('page')['props']['documents'], 'id'), ...array_column($rest->json('data'), 'id')];

    expect(count(docsPageTitles($first)))->toBe(50)
        ->and($cursor)->not->toBeNull()
        ->and($ids)->toHaveCount(51)
        ->and(array_unique($ids))->toHaveCount(51);
});

it('makes a personal document from the page and opens it', function () {
    $response = $this->actingAs($this->colleague)->post('/workspace/documents', ['title' => 'Letter to IRD', 'type' => 'docx']);
    $document = Document::query()->where('title', 'Letter to IRD')->firstOrFail();

    $response->assertRedirect("/workspace/documents/{$document->id}");

    expect($document->owner_id)->toBe($this->colleague->id)
        ->and($document->project_id)->toBeNull()
        ->and($document->versions()->first()?->path)->toBe("personal/documents/{$document->id}/v1.docx");
    Storage::disk('documents')->assertExists("personal/documents/{$document->id}/v1.docx");
});

it('makes a document in a project the person may use, and refuses one they may not', function () {
    $this->actingAs($this->member)->post('/workspace/documents', ['title' => 'Minutes', 'type' => 'docx', 'project' => $this->project->slug])->assertRedirect();
    expect(Document::query()->where('title', 'Minutes')->value('project_id'))->toBe($this->project->id);

    $this->actingAs($this->colleague)->post('/workspace/documents', ['title' => 'Sneaky', 'type' => 'docx', 'project' => $this->project->slug])
        ->assertSessionHasErrors(['project' => 'Choose one of your projects.']);
    $this->actingAs($this->colleague)->post('/workspace/documents', ['title' => 'Sneaky', 'type' => 'docx', 'project' => 'no-such-project'])
        ->assertSessionHasErrors(['project' => 'Choose one of your projects.']);

    expect(Document::query()->where('title', 'Sneaky')->exists())->toBeFalse();
});

it('refuses a personal document to a login with no person', function () {
    $this->actingAs(User::factory()->create())->post('/workspace/documents', ['title' => 'Mine', 'type' => 'docx'])->assertForbidden();
});

it('uploads a personal document, and one into a project', function () {
    $file = fn (string $name) => UploadedFile::fake()->createWithContent($name, (string) file_get_contents(resource_path('documents/blank.docx')));

    $this->actingAs($this->member)->post('/workspace/documents/upload', ['file' => $file('Draft.docx')])->assertRedirect();
    $this->actingAs($this->member)->post('/workspace/documents/upload', ['file' => $file('SRS.docx'), 'project' => $this->project->slug])->assertRedirect();

    expect(Document::query()->where('title', 'Draft')->value('owner_id'))->toBe($this->member->id)
        ->and(Document::query()->where('title', 'SRS')->value('project_id'))->toBe($this->project->id);
});

it('lists the trash the viewer may restore, and a project\'s whole trash on its tab', function () {
    docsMake($this->member, title: 'Old notes')->delete();
    docsMake($this->manager, $this->project, 'Old plan')->delete();
    docsMake($this->colleague, title: 'Their old notes')->delete();
    $shared = docsMake($this->colleague, title: 'Shared, then trashed');
    docsShare($shared, $this->member, 'edit');
    $shared->delete();

    // The page's trash: what they may restore.
    $this->actingAs($this->member)->getJson('/workspace/documents/trash')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Old notes');
    $this->actingAs($this->member)->getJson("/workspace/documents/trash?project={$this->project->slug}")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Old plan')
        ->assertJsonPath('data.0.can_delete', false);
    $this->actingAs($this->member)->get('/workspace/documents')->assertInertia(fn (AssertableInertia $page) => $page->where('trashed_count', 1));
});

it('narrows a super-admin\'s page to the chosen organization', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $elsewhere = Organization::factory()->create();
    $theirProject = Project::factory()->create(['organization_id' => $elsewhere->id]);
    docsMake($this->manager, $this->project, 'Here');
    docsMake(docsPerson($elsewhere, $theirProject, 'leader'), $theirProject, 'There');
    docsMake($boss, title: 'Boss notes');

    $all = fn () => collect(docsPageTitles($this->actingAs($boss)->get('/workspace/documents')))->sort()->values()->all();
    expect($all())->toBe(['Boss notes', 'Here', 'There']);

    $boss->forceFill(['workspace_organization_id' => $this->organization->id])->save();
    expect($all())->toBe(['Boss notes', 'Here']);
});

it('serves the Documents page and personal documents over the API', function () {
    Sanctum::actingAs($this->member);
    docsMake($this->member, title: 'My notes');
    docsMake($this->manager, $this->project, 'Project plan');

    $this->getJson('/api/v1/workspace/documents?show=mine')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'My notes')->assertJsonPath('next_cursor', null);
    $this->getJson('/api/v1/workspace/documents')->assertJsonCount(2, 'data');
    $this->postJson('/api/v1/workspace/documents', ['title' => 'Memo', 'type' => 'docx'])->assertCreated()->assertJsonPath('data.home', 'personal')->assertJsonPath('data.access', 'edit');
    $this->postJson('/api/v1/workspace/documents', ['title' => 'Memo', 'type' => 'docx', 'project' => $this->project->slug])->assertCreated()->assertJsonPath('data.home', 'project');
    $this->getJson('/api/v1/workspace/documents/trash')->assertOk()->assertJsonCount(0, 'data');
});

// ---- Move to project ----

it('moves a personal document into a project and keeps its shares', function () {
    $document = docsMake($this->member, title: 'Draft SRS');
    docsAddFile($document);
    docsShare($document, $this->colleague, 'view');

    $this->actingAs($this->member)->post("/workspace/documents/{$document->id}/move", ['project' => $this->project->slug])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $document->refresh();
    expect($document->project_id)->toBe($this->project->id)
        ->and($document->owner_id)->toBeNull()
        ->and($document->created_by)->toBe($this->member->id)
        ->and($document->shares()->count())->toBe(1)
        ->and(WorkspaceAccess::documentAccess($this->colleague, $document))->toBe('view')
        ->and(WorkspaceAccess::documentAccess($this->manager, $document))->toBe('edit')
        ->and($document->versions()->first()?->path)->toBe("personal/documents/{$document->id}/v1.docx")
        ->and(ProjectActivity::query()->where('description', 'Moved document Draft SRS into this project')->value('project_id'))->toBe($this->project->id);
});

it('turns off organization access when moving into another organization\'s project', function () {
    $elsewhere = Organization::factory()->create();
    $theirs = Project::factory()->create(['organization_id' => $elsewhere->id]);
    $theirs->ensureTeam()->update(['is_cross_organization' => true]);
    $theirs->ensureTeam()->members()->attach(WorkspaceAccess::memberFor($this->member)?->id, ['role' => 'member']);
    $document = docsMake($this->member, title: 'Open notes', attributes: ['organization_access' => 'edit']);

    $this->actingAs($this->member)->post("/workspace/documents/{$document->id}/move", ['project' => $theirs->slug])->assertSessionHasNoErrors();

    expect($document->fresh()->project_id)->toBe($theirs->id)
        ->and($document->fresh()->organization_access)->toBeNull();
});

it('refuses a project the owner may not use, a frozen, a trashed and a project document', function () {
    $document = docsMake($this->colleague, title: 'Mine');
    $this->actingAs($this->colleague)->post("/workspace/documents/{$document->id}/move", ['project' => $this->project->slug])
        ->assertSessionHasErrors(['project' => 'Choose one of your projects.']);

    $projectDocument = docsMake($this->member, $this->project, 'Plan');
    $this->actingAs($this->member)->post("/workspace/documents/{$projectDocument->id}/move", ['project' => $this->project->slug])->assertForbidden();

    $trashed = docsMake($this->member, title: 'Old');
    $trashed->delete();
    $this->actingAs($this->member)->post("/workspace/documents/{$trashed->id}/move", ['project' => $this->project->slug])->assertNotFound();

    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $frozen = docsMake($this->member, title: 'Proof');
    docsUpdatePerson($this->member, ['is_active' => false]);
    $this->actingAs($boss)->post("/workspace/documents/{$frozen->id}/move", ['project' => $this->project->slug])->assertForbidden();

    expect($document->fresh()->project_id)->toBeNull()->and($frozen->fresh()->project_id)->toBeNull();
});

// ---- Owners are deactivated, never deleted ----

it('refuses to delete a person who owns personal documents, trashed ones included', function () {
    $admin = docsPerson($this->organization, null, null, ['is_office_admin' => true]);
    $person = WorkspaceAccess::memberFor($this->colleague);
    docsMake($this->colleague, title: 'Old notes')->delete();

    $this->actingAs($admin)->delete("/workspace/members/{$person->id}")
        ->assertRedirect()
        ->assertSessionHas('workspace_flash', fn (array $flash): bool => $flash['success'] === false && $flash['message'] === 'They own personal documents; deactivate them instead.');

    Sanctum::actingAs($admin);
    $this->deleteJson("/api/v1/workspace/members/{$person->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'They own personal documents; deactivate them instead.');

    expect($person->fresh()->trashed())->toBeFalse()
        ->and(User::query()->find($this->colleague->id))->not->toBeNull();

    // Someone who owns none is deleted as before.
    $leaver = WorkspaceAccess::memberFor(docsPerson($this->organization, null));
    $this->actingAs($admin)->delete("/workspace/members/{$leaver->id}")->assertRedirect();
    expect($leaver->fresh()->trashed())->toBeTrue();
});

it('lets a super-admin with no person use their own personal documents', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $notes = docsMake($boss, title: 'Boss notes');
    $notes2 = docsMake($boss, title: 'Old boss notes');
    $notes2->delete();

    $row = fn () => collect($this->actingAs($boss)->get('/workspace/documents')->viewData('page')['props']['documents'])->firstWhere('title', 'Boss notes');
    expect($row()['access'])->toBe('edit')
        ->and($row()['frozen'])->toBeFalse()
        ->and($row()['can_delete'])->toBeTrue()
        ->and($row()['can_share'])->toBeTrue();

    $this->actingAs($boss)->getJson('/workspace/documents/trash')->assertJsonPath('data.0.title', 'Old boss notes')->assertJsonPath('data.0.can_delete', true)->assertJsonPath('data.0.frozen', false);

    Sanctum::actingAs($boss);
    $this->postJson('/api/v1/workspace/documents', ['title' => 'Memo', 'type' => 'docx'])->assertCreated()
        ->assertJsonPath('data.access', 'edit')
        ->assertJsonPath('data.frozen', false)
        ->assertJsonPath('data.can_delete', true);
});
