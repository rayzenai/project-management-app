<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * A project's documents (docs/superpowers/specs/2026-11-05-documents-design.md):
 * who may use them, the editor's signed config, its file and save calls,
 * versions, trash and the API twins.
 */

const DOCS_SECRET = 'a-test-secret-that-is-at-least-32-characters';

beforeEach(function () {
    config([
        'project-management.super_admins' => ['boss@example.com'],
        'documents.enabled' => true,
        'documents.server_url' => 'https://docs.example',
        'documents.jwt_secret' => DOCS_SECRET,
    ]);
    Storage::fake('documents');

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->project = Project::factory()->public()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->manager = docsPerson($this->organization, $this->project, 'leader');
    $this->member = docsPerson($this->organization, $this->project, 'member');
    $this->teammate = docsPerson($this->organization, $this->project, 'member');
    // In the organization, not on the team: sees the public project, never its documents.
    $this->viewer = docsPerson($this->organization, null);
});

function docsPerson(Organization $organization, ?Project $project, ?string $role = null, array $member = []): User
{
    $user = User::factory()->create();
    $person = Member::factory()->linkedTo($user)->create($member + ['organization_id' => $organization->id]);

    if ($project !== null && $role !== null) {
        $project->ensureTeam()->members()->attach($person->id, ['role' => $role]);
    }

    return $user;
}

function docsCreate(Project $project, User $user, string $title = 'Agreement', string $type = 'docx'): Document
{
    test()->actingAs($user)->post("/workspace/projects/{$project->slug}/documents", ['title' => $title, 'type' => $type])->assertRedirect();

    return Document::query()->where('project_id', $project->id)->where('title', $title)->latest('id')->firstOrFail();
}

/** @param  array<string, mixed>  $payload */
function docsToken(array $payload, string $operation, string $secret = DOCS_SECRET): string
{
    return JWT::encode(['payload' => $payload, 'operation' => $operation], $secret, 'HS256');
}

function docsCallback(Document $document, array $payload, ?string $token = null): TestResponse
{
    auth()->logout();

    return test()->withHeader('Authorization', 'Bearer '.($token ?? docsToken($payload, 'callback')))
        ->postJson(URL::signedRoute('documents.callback', ['document' => $document->id]), $payload);
}

/** A real docx, different from the blank one (its bytes plus a comment in the zip). */
function docsEditedFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'edited').'.docx';
    copy(resource_path('documents/blank.docx'), $path);
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->setArchiveComment('edited '.uniqid());
    $zip->close();

    return (string) file_get_contents($path);
}

// ---- Who ----

it('gives the project team, its program manager and its organization admins the documents, and nobody else', function () {
    $admin = docsPerson($this->organization, null, null, ['is_office_admin' => true]);
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $programManager = docsPerson($this->organization, null);
    $program = Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => WorkspaceAccess::memberFor($programManager)?->id]);
    $this->project->update(['program_id' => $program->id]);
    $deactivated = docsPerson($this->organization, $this->project, 'member', ['is_active' => false]);
    $otherAdmin = docsPerson(Organization::factory()->create(), null, null, ['is_office_admin' => true]);

    foreach ([$this->manager, $this->member, $admin, $boss, $programManager] as $user) {
        expect(WorkspaceAccess::canUseProjectDocuments($user, $this->project->fresh()))->toBeTrue();
    }

    foreach ([$this->viewer, $deactivated, $otherAdmin, null] as $user) {
        expect(WorkspaceAccess::canUseProjectDocuments($user, $this->project->fresh()))->toBeFalse();
    }
});

it('shows the Documents tab only to the people who may use it, and to nobody while documents are off', function () {
    docsCreate($this->project, $this->member);

    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('documents.documents.0.title', 'Agreement')
            ->where('documents.documents.0.can_delete', true)
            ->where('documents.trashed_count', 0));

    // Sees the public project, not its documents.
    $this->actingAs($this->viewer)->get("/workspace/projects/{$this->project->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('documents', null));

    config(['documents.enabled' => false]);

    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('documents', null));
});

it('refuses every document route to a viewer of the public project', function () {
    $document = docsCreate($this->project, $this->member);
    $base = "/workspace/projects/{$this->project->slug}/documents";

    $this->actingAs($this->viewer);
    $this->post($base, ['title' => 'Mine', 'type' => 'docx'])->assertForbidden();
    $this->post("{$base}/upload", ['file' => UploadedFile::fake()->createWithContent('x.docx', (string) file_get_contents(resource_path('documents/blank.docx')))])->assertForbidden();
    $this->get("{$base}/{$document->id}")->assertForbidden();
    $this->get("{$base}/{$document->id}/versions")->assertForbidden();
    $this->get("{$base}/{$document->id}/versions/1/download")->assertForbidden();
    $this->get("{$base}/trash")->assertForbidden();
    $this->patch("{$base}/{$document->id}", ['title' => 'Taken'])->assertForbidden();
    $this->delete("{$base}/{$document->id}")->assertForbidden();

    expect(Document::query()->count())->toBe(1)
        ->and($document->fresh()->title)->toBe('Agreement');
});

it('finds a document only under its own project', function () {
    $other = Project::factory()->create(['organization_id' => $this->organization->id]);
    $otherMember = docsPerson($this->organization, $other, 'member');
    $theirs = docsCreate($other, $otherMember, 'Their file');

    // The member may use this project's documents; the id belongs elsewhere.
    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}/documents/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->member)->delete("/workspace/projects/{$this->project->slug}/documents/{$theirs->id}")->assertNotFound();
});

// ---- Create and upload ----

it('creates a blank document as its first version and opens it in the editor', function () {
    $this->actingAs($this->member)
        ->post("/workspace/projects/{$this->project->slug}/documents", ['title' => '  Test plan  ', 'type' => 'xlsx'])
        ->assertRedirect();

    $document = Document::query()->sole();
    $version = DocumentVersion::query()->sole();

    expect($document->title)->toBe('Test plan')
        ->and($document->file_type)->toBe('xlsx')
        ->and($document->version)->toBe(1)
        ->and($document->created_by)->toBe($this->member->id)
        ->and($version->source)->toBe('created')
        ->and($version->path)->toBe("projects/{$this->project->id}/documents/{$document->id}/v1.xlsx")
        ->and($version->sha256)->toBe(hash_file('sha256', resource_path('documents/blank.xlsx')));
    Storage::disk('documents')->assertExists($version->path);

    $activity = ProjectActivity::query()->where('subject_type', 'document')->sole();
    expect($activity->description)->toBe('Created document Test plan')
        ->and($activity->user_id)->toBe($this->member->id);
});

it('refuses a type that is not a document, spreadsheet or presentation', function () {
    $this->actingAs($this->member)
        ->post("/workspace/projects/{$this->project->slug}/documents", ['title' => 'Script', 'type' => 'exe'])
        ->assertSessionHasErrors('type');

    expect(Document::query()->count())->toBe(0);
});

it('uploads a real Word file and names it after the file', function () {
    $file = UploadedFile::fake()->createWithContent('SRS draft.docx', (string) file_get_contents(resource_path('documents/blank.docx')));

    $this->actingAs($this->member)
        ->post("/workspace/projects/{$this->project->slug}/documents/upload", ['file' => $file])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $document = Document::query()->sole();
    expect($document->title)->toBe('SRS draft')
        ->and($document->file_type)->toBe('docx')
        ->and(DocumentVersion::query()->sole()->source)->toBe('uploaded');
    expect(ProjectActivity::query()->where('subject_type', 'document')->sole()->description)->toBe('Uploaded document SRS draft');
});

it('refuses an upload that is not really the Office file its name says', function (string $name, string $contents) {
    $this->actingAs($this->member)
        ->post("/workspace/projects/{$this->project->slug}/documents/upload", ['file' => UploadedFile::fake()->createWithContent($name, $contents)])
        ->assertSessionHasErrors('file');

    expect(Document::query()->count())->toBe(0);
})->with([
    'text renamed .docx' => ['notes.docx', 'just some text'],
    'a spreadsheet renamed .docx' => ['sheet.docx', fn () => (string) file_get_contents(resource_path('documents/blank.xlsx'))],
    'an old .doc' => ['old.doc', 'binary'],
    'a script' => ['run.php', '<?php echo 1;'],
]);

// ---- The editor's config ----

it('hands the editor a signed config that names the person without their email', function () {
    $document = docsCreate($this->project, $this->member);

    $response = $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}/documents/{$document->id}");

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Documents/Edit')
        ->where('document.title', 'Agreement')
        ->where('versions.0.version', 1)
        ->where('editor.server_url', 'https://docs.example')
        ->where('editor.config.document.key', $document->editor_key)
        ->where('editor.config.documentType', 'word')
        ->where('editor.config.editorConfig.user.id', (string) $this->member->id)
        ->where('editor.config.editorConfig.customization.ai.disabled', true)
        ->where('editor.config.editorConfig.customization.plugins', false)
        ->where('editor.config.document.permissions.chat', false));

    $page = $response->viewData('page');
    $config = $page['props']['editor']['config'];
    $token = $config['token'];
    unset($config['token']);

    // The token is the whole config, signed with the shared secret, and it
    // expires with the file link.
    $claims = json_decode((string) json_encode(JWT::decode($token, new Key(DOCS_SECRET, 'HS256'))), true);
    expect($claims['exp'] - $claims['iat'])->toBe(60 * 60);
    unset($claims['iat'], $claims['exp']);
    expect($claims)->toEqual($config)
        // Kept in the browser's history only encrypted.
        ->and($page['encryptHistory'])->toBeTrue()
        ->and(json_encode($config))->not->toContain($this->member->email)
        ->and($config['document']['url'])->toContain('/documents/'.$document->id.'/file/1?expires=')
        ->and($config['editorConfig']['callbackUrl'])->toContain('/documents/'.$document->id.'/callback?signature=');
});

// ---- The editor's file call ----

it('serves the file to the editor only with the signed link and its download token', function () {
    $document = docsCreate($this->project, $this->member);
    $url = URL::temporarySignedRoute('documents.file', now()->addHour(), ['document' => $document->id, 'version' => 1]);
    auth()->logout();

    $this->withHeader('Authorization', 'Bearer '.docsToken(['url' => $url], 'download'))->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    // No token, a save token, another secret, no signature, an expired link.
    $this->withHeader('Authorization', '')->get($url)->assertForbidden();
    $this->withHeader('Authorization', 'Bearer '.docsToken(['url' => $url], 'callback'))->get($url)->assertForbidden();
    $this->withHeader('Authorization', 'Bearer '.docsToken(['url' => $url], 'download', str_repeat('x', 40)))->get($url)->assertForbidden();
    $this->withHeader('Authorization', 'Bearer '.docsToken(['url' => $url], 'download'))->get("/documents/{$document->id}/file/1")->assertForbidden();

    $this->travel(2)->hours();
    $this->withHeader('Authorization', 'Bearer '.docsToken(['url' => $url], 'download'))->get($url)->assertForbidden();
});

// ---- The editor's save call ----

it('keeps a save as the next version, credited to the editor, and starts the next session from it', function () {
    $document = docsCreate($this->project, $this->member);
    $key = $document->editor_key;
    $edited = docsEditedFile();
    Http::fake(['https://docs.example/cache/*' => Http::response($edited)]);

    docsCallback($document, ['key' => $key, 'status' => 2, 'url' => 'https://docs.example/cache/files/data/output.docx', 'users' => [(string) $this->teammate->id]])
        ->assertOk()
        ->assertExactJson(['error' => 0]);

    $document->refresh();
    $version = DocumentVersion::query()->where('version', 2)->sole();

    expect($document->version)->toBe(2)
        ->and($document->editor_key)->not->toBe($key)
        ->and($document->updated_by)->toBe($this->teammate->id)
        ->and($version->source)->toBe('edited')
        ->and($version->user_id)->toBe($this->teammate->id)
        ->and($version->sha256)->toBe(hash('sha256', $edited));
    expect(Storage::disk('documents')->get($version->path))->toBe($edited);

    $activity = ProjectActivity::query()->where('description', 'Saved document Agreement (version 2)')->sole();
    expect($activity->user_id)->toBe($this->teammate->id);
});

it('keeps a forced save without ending the session', function () {
    $document = docsCreate($this->project, $this->member);
    $key = $document->editor_key;
    Http::fake(['https://docs.example/*' => Http::response(docsEditedFile())]);

    docsCallback($document, ['key' => $key, 'status' => 6, 'url' => 'https://docs.example/cache/out.docx', 'users' => [(string) $this->member->id]])
        ->assertExactJson(['error' => 0]);

    expect($document->fresh()->version)->toBe(2)
        ->and($document->fresh()->editor_key)->toBe($key);
});

it('does nothing for a session already saved, editing or closed unchanged', function (int $status, bool $staleKey) {
    $document = docsCreate($this->project, $this->member);
    $key = $document->editor_key;
    Http::fake();

    docsCallback($document, ['key' => $staleKey ? 'an-old-key' : $key, 'status' => $status, 'url' => 'https://docs.example/cache/out.docx'])
        ->assertExactJson(['error' => 0]);

    Http::assertNothingSent();
    expect($document->fresh()->version)->toBe(1)
        ->and($document->fresh()->editor_key)->toBe($key);
})->with([
    'saved already' => [2, true],
    'editing' => [1, false],
    'closed with no changes' => [4, false],
]);

it('keeps what the editor sends after an error building it, like any save', function (int $status, bool $endsSession) {
    $document = docsCreate($this->project, $this->member);
    $key = $document->editor_key;
    Http::fake(['https://docs.example/*' => Http::response(docsEditedFile())]);

    docsCallback($document, ['key' => $key, 'status' => $status, 'url' => 'https://docs.example/cache/out.docx', 'users' => [(string) $this->member->id]])
        ->assertExactJson(['error' => 0]);

    expect($document->fresh()->version)->toBe(2)
        ->and($document->fresh()->editor_key !== $key)->toBe($endsSession);
})->with([
    'everyone closed it (3)' => [3, true],
    'a forced save (7)' => [7, false],
]);

it('refuses a saved file that is not on the editor itself', function (string $url) {
    $document = docsCreate($this->project, $this->member);
    Http::fake();

    docsCallback($document, ['key' => $document->editor_key, 'status' => 2, 'url' => $url])
        ->assertExactJson(['error' => 1]);

    Http::assertNothingSent();
    expect($document->fresh()->version)->toBe(1);
})->with([
    'another host' => ['https://evil.example/output.docx'],
    'plain http' => ['http://docs.example/output.docx'],
    'another port' => ['https://docs.example:8443/output.docx'],
    'a look-alike host' => ['https://docs.example.evil.example/output.docx'],
    'credentials' => ['https://user:pass@docs.example/output.docx'],
]);

it('asks the editor to send a save again when the file could not be fetched', function () {
    $document = docsCreate($this->project, $this->member);
    $key = $document->editor_key;
    Http::fake(['https://docs.example/*' => Http::response('gone', 404)]);

    docsCallback($document, ['key' => $key, 'status' => 2, 'url' => 'https://docs.example/cache/out.docx'])
        ->assertExactJson(['error' => 1]);

    expect($document->fresh()->version)->toBe(1)
        ->and($document->fresh()->editor_key)->toBe($key);
});

it('makes no new version for the same file, yet starts a new session', function () {
    $document = docsCreate($this->project, $this->member);
    $key = $document->editor_key;
    Http::fake(['https://docs.example/*' => Http::response((string) file_get_contents(resource_path('documents/blank.docx')))]);

    docsCallback($document, ['key' => $key, 'status' => 2, 'url' => 'https://docs.example/cache/out.docx'])
        ->assertExactJson(['error' => 0]);

    expect($document->fresh()->version)->toBe(1)
        ->and(DocumentVersion::query()->count())->toBe(1)
        ->and($document->fresh()->editor_key)->not->toBe($key);
});

it('ignores a save report that the editor did not sign', function (?string $token, bool $signedUrl) {
    $document = docsCreate($this->project, $this->member);
    $payload = ['key' => $document->editor_key, 'status' => 2, 'url' => 'https://docs.example/cache/out.docx'];
    Http::fake();
    auth()->logout();

    $url = $signedUrl ? URL::signedRoute('documents.callback', ['document' => $document->id]) : "/documents/{$document->id}/callback";

    $this->withHeader('Authorization', $token === null ? '' : 'Bearer '.$token)->postJson($url, $payload)
        ->assertForbidden();

    Http::assertNothingSent();
    expect($document->fresh()->version)->toBe(1);
})->with([
    'no token' => [null, true],
    'a download token' => [fn () => docsToken(['url' => 'x'], 'download'), true],
    'another secret' => [fn () => docsToken(['key' => 'k', 'status' => 2], 'callback', str_repeat('y', 40)), true],
    'no URL signature' => [fn () => docsToken(['key' => 'k', 'status' => 2], 'callback'), false],
]);

it('takes the save from the signed token, never from the request body', function () {
    $document = docsCreate($this->project, $this->member);
    Http::fake();
    $signed = ['key' => $document->editor_key, 'status' => 4];

    // The body claims a save from elsewhere; the token says closed, no changes.
    docsCallback($document, ['key' => $document->editor_key, 'status' => 2, 'url' => 'https://evil.example/x.docx'], docsToken($signed, 'callback'))
        ->assertExactJson(['error' => 0]);

    Http::assertNothingSent();
    expect($document->fresh()->version)->toBe(1);
});

// ---- Versions, rename, trash ----

it('lists the versions and downloads each under the document name', function () {
    $document = docsCreate($this->project, $this->member);
    Http::fake(['https://docs.example/*' => Http::response(docsEditedFile())]);
    docsCallback($document, ['key' => $document->editor_key, 'status' => 2, 'url' => 'https://docs.example/out.docx', 'users' => [(string) $this->teammate->id]]);

    $this->actingAs($this->member)->getJson("/workspace/projects/{$this->project->slug}/documents/{$document->id}/versions")
        ->assertOk()
        ->assertJsonPath('data.0.version', 2)
        ->assertJsonPath('data.0.source', 'edited')
        ->assertJsonPath('data.0.user.name', $this->teammate->name)
        ->assertJsonPath('data.1.version', 1);

    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}/documents/{$document->id}/versions/1/download")
        ->assertOk()
        ->assertDownload('Agreement (version 1).docx');
    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}/documents/{$document->id}/versions/2/download")
        ->assertDownload('Agreement.docx');
    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}/documents/{$document->id}/versions/3/download")
        ->assertNotFound();
});

it('renames a document and logs it', function () {
    $document = docsCreate($this->project, $this->member);

    $this->actingAs($this->teammate)
        ->patch("/workspace/projects/{$this->project->slug}/documents/{$document->id}", ['title' => 'Signed agreement'])
        ->assertRedirect();

    expect($document->fresh()->title)->toBe('Signed agreement')
        ->and($document->fresh()->updated_by)->toBe($this->teammate->id)
        ->and(ProjectActivity::query()->where('description', 'Renamed document Agreement to Signed agreement')->exists())->toBeTrue();
});

it('lets the creator and whoever runs the project move a document to the trash, never another teammate', function () {
    $mine = docsCreate($this->project, $this->member, 'Mine');
    $theirs = docsCreate($this->project, $this->teammate, 'Theirs');
    $base = "/workspace/projects/{$this->project->slug}/documents";

    $this->actingAs($this->member)->delete("{$base}/{$theirs->id}")->assertForbidden();
    expect($theirs->fresh()->trashed())->toBeFalse();

    $this->actingAs($this->member)->delete("{$base}/{$mine->id}")->assertRedirect();
    $this->actingAs($this->manager)->delete("{$base}/{$theirs->id}")->assertRedirect();

    expect($mine->fresh()->trashed())->toBeTrue()
        ->and($mine->fresh()->deleted_by)->toBe($this->member->id)
        ->and($theirs->fresh()->trashed())->toBeTrue()
        ->and(DocumentVersion::query()->count())->toBe(2);
    Storage::disk('documents')->assertExists(DocumentVersion::query()->pluck('path')->all());

    // Trashed: out of the list and the editor, into the trash.
    $this->actingAs($this->member)->get("{$base}/{$mine->id}")->assertNotFound();
    $this->actingAs($this->member)->getJson("{$base}/trash")->assertJsonCount(2, 'data');
    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('documents.documents', [])->where('documents.trashed_count', 2));

    // Restore: the same rule.
    $this->actingAs($this->member)->post("{$base}/{$theirs->id}/restore")->assertForbidden();
    $this->actingAs($this->member)->post("{$base}/{$mine->id}/restore")->assertRedirect();

    expect($mine->fresh()->trashed())->toBeFalse()
        ->and($mine->fresh()->deleted_by)->toBeNull()
        ->and(ProjectActivity::query()->where('description', 'Moved document Mine to the trash')->exists())->toBeTrue()
        ->and(ProjectActivity::query()->where('description', 'Restored document Mine')->exists())->toBeTrue();
});

it('clears the history the editor page was kept in when its person signs out', function () {
    $this->actingAs($this->member)->post('/workspace/logout');

    expect(session(SessionKey::CLEAR_HISTORY))->toBeTrue();
});

it('lets a creator who left the team trash nothing', function () {
    $document = docsCreate($this->project, $this->member);
    $this->project->team()?->members()->detach(WorkspaceAccess::memberFor($this->member)?->id);

    $this->actingAs($this->member)->delete("/workspace/projects/{$this->project->slug}/documents/{$document->id}")->assertForbidden();
    expect($document->fresh()->trashed())->toBeFalse();
});

it('still keeps a save of a document trashed while someone had it open', function () {
    $document = docsCreate($this->project, $this->member);
    $key = $document->editor_key;
    $this->actingAs($this->member)->delete("/workspace/projects/{$this->project->slug}/documents/{$document->id}");
    Http::fake(['https://docs.example/*' => Http::response(docsEditedFile())]);

    docsCallback($document, ['key' => $key, 'status' => 2, 'url' => 'https://docs.example/out.docx'])
        ->assertExactJson(['error' => 0]);

    expect(Document::withTrashed()->find($document->id)->version)->toBe(2);
});

// ---- API ----

it('serves the same documents over the API, with the editor config', function () {
    Sanctum::actingAs($this->member);
    $base = "/api/v1/workspace/projects/{$this->project->slug}/documents";

    $created = $this->postJson($base, ['title' => 'Minutes', 'type' => 'docx'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Minutes')
        ->assertJsonPath('data.can_delete', true);
    $id = $created->json('data.id');

    $this->postJson("{$base}/upload", ['file' => UploadedFile::fake()->createWithContent('Deck.pptx', (string) file_get_contents(resource_path('documents/blank.pptx')))])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'Presentation');

    $this->getJson($base)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("{$base}/{$id}")
        ->assertOk()
        ->assertJsonPath('data.document.title', 'Minutes')
        ->assertJsonPath('data.editor.config.document.key', Document::query()->find($id)?->editor_key);
    $this->patchJson("{$base}/{$id}", ['title' => 'Minutes, 5 November'])->assertOk()->assertJsonPath('data.title', 'Minutes, 5 November');
    $this->deleteJson("{$base}/{$id}")->assertOk();
    $this->getJson("{$base}/trash")->assertJsonCount(1, 'data');
    $this->postJson("{$base}/{$id}/restore")->assertOk()->assertJsonPath('data.deleted_at', null);

    Sanctum::actingAs($this->viewer);
    $this->getJson($base)->assertForbidden();
    $this->getJson("{$base}/{$id}")->assertForbidden();
});
