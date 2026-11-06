<?php

use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
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

it('lists the trash the viewer may restore, for one project too', function () {
    docsMake($this->member, title: 'Old notes')->delete();
    docsMake($this->manager, $this->project, 'Old plan')->delete();
    docsMake($this->colleague, title: 'Their old notes')->delete();

    $this->actingAs($this->member)->getJson('/workspace/documents/trash')->assertOk()->assertJsonCount(2, 'data');
    $this->actingAs($this->member)->getJson("/workspace/documents/trash?project={$this->project->slug}")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Old plan')
        ->assertJsonPath('data.0.can_delete', false);
    $this->actingAs($this->member)->get('/workspace/documents')->assertInertia(fn (AssertableInertia $page) => $page->where('trashed_count', 2));
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
