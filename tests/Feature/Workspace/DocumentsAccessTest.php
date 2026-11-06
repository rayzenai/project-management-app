<?php

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/*
 * Who may open, edit and share a document
 * (docs/superpowers/specs/2026-11-06-documents-workspace-design.md):
 * personal documents, organization access, individual shares, frozen
 * documents, and the list's query agreeing with the rule.
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
    $this->owner = docsPerson($this->organization, null);
});

// ---- Data ----

it('stores a personal document\'s versions under personal/', function () {
    $document = docsMake($this->owner);

    expect($document->isPersonal())->toBeTrue()
        ->and(DocumentVersion::pathFor($document, 1))->toBe("personal/documents/{$document->id}/v1.docx");
});

it('refuses to delete a login that owns a personal document', function () {
    docsMake($this->owner);

    expect(fn () => $this->owner->delete())->toThrow(QueryException::class);
});

it('keeps one share per person per document', function () {
    $document = docsMake($this->owner);
    $colleague = WorkspaceAccess::memberFor(docsPerson($this->organization, null));

    DocumentShare::query()->create(['document_id' => $document->id, 'member_id' => $colleague->id, 'permission' => 'view', 'shared_by' => $this->owner->id]);

    expect(fn () => DocumentShare::query()->create(['document_id' => $document->id, 'member_id' => $colleague->id, 'permission' => 'edit', 'shared_by' => $this->owner->id]))
        ->toThrow(QueryException::class);
});

// ---- The access level ----

it('gives the owner edit and nobody else anything on a private document', function () {
    $document = docsMake($this->owner);
    $project = Project::factory()->create(['organization_id' => $this->organization->id]);
    $teammate = docsPerson($this->organization, $project, 'leader');
    $admin = docsPerson($this->organization, null, null, ['is_office_admin' => true]);
    $programManager = docsPerson($this->organization, null);
    Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => WorkspaceAccess::memberFor($programManager)?->id]);
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    expect(WorkspaceAccess::documentAccess($this->owner, $document))->toBe('edit');

    foreach ([$teammate, $admin, $programManager, $boss, null] as $user) {
        expect(WorkspaceAccess::documentAccess($user, $document))->toBeNull();
    }
});

it('gives everyone in the organization the chosen level', function () {
    $document = docsMake($this->owner, attributes: ['organization_access' => 'view']);
    $colleague = docsPerson($this->organization, null);
    $deactivated = docsPerson($this->organization, null, null, ['is_active' => false]);
    $other = Organization::factory()->create();
    $outsider = docsPerson($other, null);

    expect(WorkspaceAccess::documentAccess($colleague, $document))->toBe('view')
        ->and(WorkspaceAccess::documentAccess($deactivated, $document))->toBeNull()
        ->and(WorkspaceAccess::documentAccess($outsider, $document))->toBeNull();

    // A project document opens to the project's organization, whoever made it.
    $project = Project::factory()->create(['organization_id' => $other->id]);
    $maker = docsPerson($other, $project, 'leader');
    $projectDocument = docsMake($maker, $project, attributes: ['organization_access' => 'edit']);

    expect(WorkspaceAccess::documentAccess($outsider, $projectDocument))->toBe('edit')
        ->and(WorkspaceAccess::documentAccess($colleague, $projectDocument))->toBeNull();
});

it('counts a share only while the person is active and in the organization', function () {
    $document = docsMake($this->owner);
    $colleague = docsPerson($this->organization, null);
    docsShare($document, $colleague, 'view');

    expect(WorkspaceAccess::documentAccess($colleague, $document))->toBe('view');

    docsUpdatePerson($colleague, ['is_active' => false]);
    expect(WorkspaceAccess::documentAccess($colleague, $document))->toBeNull();

    docsUpdatePerson($colleague, ['is_active' => true]);
    expect(WorkspaceAccess::documentAccess($colleague, $document))->toBe('view');

    docsUpdatePerson($colleague, ['organization_id' => Organization::factory()->create()->id]);
    expect(WorkspaceAccess::documentAccess($colleague, $document))->toBeNull();
});

it('gives the highest level', function () {
    $project = Project::factory()->create(['organization_id' => $this->organization->id]);
    $teammate = docsPerson($this->organization, $project, 'member');
    $projectDocument = docsMake($teammate, $project);
    docsShare($projectDocument, $teammate, 'view');

    $document = docsMake($this->owner, attributes: ['organization_access' => 'view']);
    $colleague = docsPerson($this->organization, null);
    docsShare($document, $colleague, 'edit');

    expect(WorkspaceAccess::documentAccess($teammate, $projectDocument))->toBe('edit')
        ->and(WorkspaceAccess::documentAccess($colleague, $document))->toBe('edit');
});

it('follows the owner to a new organization', function () {
    $document = docsMake($this->owner, attributes: ['organization_access' => 'view']);
    $oldColleague = docsPerson($this->organization, null);
    $sharedWith = docsPerson($this->organization, null);
    docsShare($document, $sharedWith, 'edit');
    $newOrganization = Organization::factory()->create();
    $newColleague = docsPerson($newOrganization, null);

    docsUpdatePerson($this->owner, ['organization_id' => $newOrganization->id]);

    expect(WorkspaceAccess::documentAccess($this->owner, $document))->toBe('edit')
        ->and(WorkspaceAccess::documentAccess($oldColleague, $document))->toBeNull()
        ->and(WorkspaceAccess::documentAccess($sharedWith, $document))->toBeNull()
        ->and(WorkspaceAccess::documentAccess($newColleague, $document))->toBe('view')
        ->and(WorkspaceAccess::documentOrganizationId($document))->toBe($newOrganization->id);
});

it('freezes a personal document when its owner is deactivated', function () {
    $document = docsMake($this->owner, attributes: ['organization_access' => 'edit']);
    $colleague = docsPerson($this->organization, null);
    docsShare($document, $colleague, 'edit');
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    docsUpdatePerson($this->owner, ['is_active' => false]);

    expect(WorkspaceAccess::isDocumentFrozen($document))->toBeTrue()
        ->and(WorkspaceAccess::documentAccess($this->owner, $document))->toBeNull()
        ->and(WorkspaceAccess::documentAccess($colleague, $document))->toBeNull()
        ->and(WorkspaceAccess::documentAccess($boss, $document))->toBe('view');

    foreach ([$this->owner, $boss, $colleague] as $user) {
        expect(WorkspaceAccess::canShareDocument($user, $document))->toBeFalse()
            ->and(WorkspaceAccess::canDeleteDocument($user, $document))->toBeFalse()
            ->and(WorkspaceAccess::canMoveDocument($user, $document))->toBeFalse();
    }

    docsUpdatePerson($this->owner, ['is_active' => true]);

    expect(WorkspaceAccess::isDocumentFrozen($document))->toBeFalse()
        ->and(WorkspaceAccess::documentAccess($this->owner, $document))->toBe('edit')
        ->and(WorkspaceAccess::documentAccess($colleague, $document))->toBe('edit')
        ->and(WorkspaceAccess::documentAccess($boss, $document))->toBeNull()
        ->and(WorkspaceAccess::canShareDocument($this->owner, $document))->toBeTrue();
});

it('lets whoever runs the project and the maker share a project document, not the rest of the team', function () {
    $project = Project::factory()->create(['organization_id' => $this->organization->id]);
    $manager = docsPerson($this->organization, $project, 'leader');
    $maker = docsPerson($this->organization, $project, 'member');
    $teammate = docsPerson($this->organization, $project, 'member');
    $document = docsMake($maker, $project);

    expect(WorkspaceAccess::canShareDocument($manager, $document))->toBeTrue()
        ->and(WorkspaceAccess::canShareDocument($maker, $document))->toBeTrue()
        ->and(WorkspaceAccess::canShareDocument($teammate, $document))->toBeFalse()
        ->and(WorkspaceAccess::documentAccess($teammate, $document))->toBe('edit')
        ->and(WorkspaceAccess::canMoveDocument($maker, $document))->toBeFalse();
});

it('shares only with active people of the document\'s organization, never its owner', function () {
    $document = docsMake($this->owner);
    $colleague = WorkspaceAccess::memberFor(docsPerson($this->organization, null));
    $deactivated = WorkspaceAccess::memberFor(docsPerson($this->organization, null, null, ['is_active' => false]));
    $outsider = WorkspaceAccess::memberFor(docsPerson(Organization::factory()->create(), null));

    expect(WorkspaceAccess::canShareDocumentWith($document, $colleague))->toBeTrue()
        ->and(WorkspaceAccess::canShareDocumentWith($document, $deactivated))->toBeFalse()
        ->and(WorkspaceAccess::canShareDocumentWith($document, $outsider))->toBeFalse()
        ->and(WorkspaceAccess::canShareDocumentWith($document, WorkspaceAccess::memberFor($this->owner)))->toBeFalse();
});

it('lets only an active person with an organization, or a super-admin, make personal documents', function () {
    $loginOnly = User::factory()->create();

    expect(WorkspaceAccess::canCreatePersonalDocuments($this->owner))->toBeTrue()
        ->and(WorkspaceAccess::canCreatePersonalDocuments(User::factory()->create(['email' => 'boss@example.com'])))->toBeTrue()
        ->and(WorkspaceAccess::canCreatePersonalDocuments($loginOnly))->toBeFalse()
        ->and(WorkspaceAccess::canCreatePersonalDocuments(null))->toBeFalse();

    config(['documents.enabled' => false]);
    expect(WorkspaceAccess::canCreatePersonalDocuments($this->owner))->toBeFalse();
});

it('lists exactly the documents the level opens', function () {
    $other = Organization::factory()->create();
    $project = Project::factory()->create(['organization_id' => $this->organization->id]);
    $quiet = Project::factory()->create(['organization_id' => $this->organization->id]);
    $users = [
        'owner' => $this->owner,
        'teammate' => docsPerson($this->organization, $project, 'leader'),
        'admin' => docsPerson($this->organization, null, null, ['is_office_admin' => true]),
        'programManager' => docsPerson($this->organization, null),
        'boss' => User::factory()->create(['email' => 'boss@example.com']),
        'colleague' => docsPerson($this->organization, null),
        'outsider' => docsPerson($other, null),
        'deactivated' => docsPerson($this->organization, $project, 'member', ['is_active' => false]),
        'crossOutsider' => docsPerson($other, null),
        'leaver' => docsPerson($this->organization, null),
        'quietManager' => docsPerson($this->organization, $quiet, 'leader'),
    ];
    $program = Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => WorkspaceAccess::memberFor($users['programManager'])?->id]);
    $project->update(['program_id' => $program->id]);
    $project->ensureTeam()->update(['is_cross_organization' => true]);
    $project->ensureTeam()->members()->attach(WorkspaceAccess::memberFor($users['crossOutsider'])?->id, ['role' => 'member']);

    docsMake($users['owner'], title: 'Private');
    docsMake($users['owner'], title: 'Office view', attributes: ['organization_access' => 'view']);
    docsMake($users['owner'], title: 'Office edit', attributes: ['organization_access' => 'edit']);
    docsShare(docsMake($users['owner'], title: 'Shared'), $users['teammate'], 'view');
    docsShare(docsMake($users['owner'], title: 'Shared outside'), $users['outsider'], 'edit');
    docsMake($users['teammate'], $project, 'In the project');
    docsShare(docsMake($users['quietManager'], $quiet, 'Quiet, shared'), $users['colleague'], 'view');
    docsMake($users['quietManager'], $quiet, 'Quiet, office', ['organization_access' => 'view']);
    docsMake($users['owner'], title: 'Trashed')->delete();
    $leaving = docsMake($users['leaver'], title: 'Proof', attributes: ['organization_access' => 'view']);
    docsShare($leaving, $users['colleague'], 'edit');
    docsMake($users['leaver'], title: 'Trashed proof')->delete();
    docsUpdatePerson($users['leaver'], ['is_active' => false]);

    $documents = Document::withTrashed()->get();

    foreach ([...$users, 'guest' => null] as $name => $user) {
        $expected = $documents->filter(fn (Document $d): bool => WorkspaceAccess::documentAccess($user, $d) !== null)->pluck('title')->sort()->values()->all();
        $listed = Document::withTrashed()->visibleTo($user)->pluck('title')->sort()->values()->all();

        expect($listed)->toBe($expected, "{$name} sees what the level opens");
    }

    // The matrix means something: a few known answers.
    expect(Document::withTrashed()->visibleTo($users['colleague'])->pluck('title')->sort()->values()->all())
        ->toBe(['Office edit', 'Office view', 'Quiet, office', 'Quiet, shared'])
        ->and(Document::withTrashed()->visibleTo($users['boss'])->pluck('title')->sort()->values()->all())
        ->toBe(['In the project', 'Proof', 'Quiet, office', 'Quiet, shared', 'Trashed proof']);
});
