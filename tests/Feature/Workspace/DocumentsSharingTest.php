<?php

use App\Console\Commands\SendEmailUpdates;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Notifications\DocumentShared;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * Sharing a document: everyone in its organization, or chosen people
 * (docs/superpowers/specs/2026-11-06-documents-workspace-design.md).
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
    $this->colleague = docsPerson($this->organization, null);
    $this->document = docsMake($this->owner, title: 'Notes');
});

function docsMemberId(User $user): int
{
    return (int) WorkspaceAccess::memberFor($user)?->id;
}

it('shares a personal document with someone in the organization and tells them', function () {
    $this->actingAs($this->owner)
        ->post("/workspace/documents/{$this->document->id}/shares", ['member_id' => docsMemberId($this->colleague), 'permission' => 'view'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(WorkspaceAccess::documentAccess($this->colleague, $this->document))->toBe('view');

    $notification = $this->colleague->notifications()->first();
    expect($notification?->type)->toBe(DocumentShared::class)
        ->and($notification?->data['kind'])->toBe('document_shared')
        ->and($notification?->data['url'])->toBe("/workspace/documents/{$this->document->id}")
        ->and($notification?->data['body'])->toBe("{$this->owner->name} shared Notes with you. You can view it.")
        ->and($notification?->data['task'])->toBeNull()
        ->and(SendEmailUpdates::item($notification->data)['title'])->toBe('Shared with you: Notes')
        ->and(in_array(DocumentShared::class, SendEmailUpdates::KINDS, true))->toBeTrue();

    $this->actingAs($this->owner)->getJson("/workspace/documents/{$this->document->id}/sharing")
        ->assertOk()
        ->assertJsonPath('data.home.type', 'personal')
        ->assertJsonPath('data.organization.name', 'SDOC')
        ->assertJsonPath('data.organization_access', null)
        ->assertJsonPath('data.shares.0.member.name', $this->colleague->name)
        ->assertJsonPath('data.shares.0.permission', 'view')
        ->assertJsonPath('data.shares.0.effective', true);
});

it('answers an outsider, an unknown id and another organization\'s person with one generic message', function () {
    $outsider = docsPerson(Organization::factory()->create(), null);
    $answers = [];

    foreach ([docsMemberId($outsider), 999999] as $memberId) {
        $response = $this->actingAs($this->owner)->postJson("/workspace/documents/{$this->document->id}/shares", ['member_id' => $memberId, 'permission' => 'edit'])
            ->assertUnprocessable();
        $answers[] = $response->json('errors.member_id.0');
    }

    expect(array_unique($answers))->toBe(['Choose someone in SDOC.'])
        ->and($answers[0])->not->toContain($outsider->name)
        ->and(DocumentShare::query()->count())->toBe(0);
});

it('refuses the owner, an inactive person and a non-owner sharing', function () {
    $inactive = docsPerson($this->organization, null, null, ['is_active' => false]);
    $url = "/workspace/documents/{$this->document->id}/shares";

    $this->actingAs($this->owner)->postJson($url, ['member_id' => docsMemberId($this->owner), 'permission' => 'edit'])
        ->assertUnprocessable()->assertJsonPath('errors.member_id.0', "You can't share this with {$this->owner->name}.");
    $this->actingAs($this->owner)->postJson($url, ['member_id' => docsMemberId($inactive), 'permission' => 'edit'])
        ->assertUnprocessable()->assertJsonPath('errors.member_id.0', "You can't share this with {$inactive->name}.");
    $this->actingAs($this->owner)->postJson($url, ['member_id' => docsMemberId($this->colleague), 'permission' => 'own'])->assertUnprocessable();

    docsShare($this->document, $this->colleague, 'edit');
    $someone = docsPerson($this->organization, null);
    // Can edit is not Can share.
    $this->actingAs($this->colleague)->postJson($url, ['member_id' => docsMemberId($someone), 'permission' => 'edit'])->assertForbidden();
    $this->actingAs($this->colleague)->getJson("/workspace/documents/{$this->document->id}/sharing")->assertForbidden();
    $this->actingAs($this->colleague)->putJson("/workspace/documents/{$this->document->id}/sharing/organization", ['access' => 'edit'])->assertForbidden();

    expect(DocumentShare::query()->count())->toBe(1);
});

it('changes and removes a share without telling anyone', function () {
    $share = docsShare($this->document, $this->colleague, 'edit');

    $this->actingAs($this->owner)->patch("/workspace/documents/{$this->document->id}/shares/{$share->id}", ['permission' => 'view'])->assertRedirect();
    expect($share->fresh()->permission)->toBe('view');

    $this->actingAs($this->owner)->delete("/workspace/documents/{$this->document->id}/shares/{$share->id}")->assertRedirect();
    expect(DocumentShare::query()->count())->toBe(0)
        ->and($this->colleague->notifications()->count())->toBe(0);

    // A share of another document is not this one's.
    $other = docsShare(docsMake($this->owner, title: 'Other'), $this->colleague, 'edit');
    $this->actingAs($this->owner)->delete("/workspace/documents/{$this->document->id}/shares/{$other->id}")->assertNotFound();
});

it('opens a document to the organization and closes it', function () {
    $this->actingAs($this->owner)->put("/workspace/documents/{$this->document->id}/sharing/organization", ['access' => 'view'])->assertRedirect();
    expect($this->document->fresh()->organization_access)->toBe('view')
        ->and(WorkspaceAccess::documentAccess($this->colleague, $this->document->fresh()))->toBe('view')
        ->and($this->colleague->notifications()->count())->toBe(0);

    $this->actingAs($this->owner)->put("/workspace/documents/{$this->document->id}/sharing/organization", ['access' => null])->assertRedirect();
    expect($this->document->fresh()->organization_access)->toBeNull();

    $this->actingAs($this->owner)->putJson("/workspace/documents/{$this->document->id}/sharing/organization", ['access' => 'all'])->assertUnprocessable();
});

it('has no organization to open a super-admin\'s own document to when they have no person', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $document = docsMake($boss, title: 'Boss notes');

    $this->actingAs($boss)->putJson("/workspace/documents/{$document->id}/sharing/organization", ['access' => 'view'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.access.0', 'This document has no organization to share with.');
});

it('lets a project\'s manager and its maker share a project document and records it', function () {
    $project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal']);
    $manager = docsPerson($this->organization, $project, 'leader');
    $maker = docsPerson($this->organization, $project, 'member');
    $teammate = docsPerson($this->organization, $project, 'member');
    $document = docsMake($maker, $project, 'Plan');

    $this->actingAs($maker)->post("/workspace/documents/{$document->id}/shares", ['member_id' => docsMemberId($this->colleague), 'permission' => 'edit'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->put("/workspace/documents/{$document->id}/sharing/organization", ['access' => 'view'])->assertSessionHasNoErrors();
    $share = DocumentShare::query()->firstOrFail();
    $this->actingAs($manager)->patch("/workspace/documents/{$document->id}/shares/{$share->id}", ['permission' => 'view']);
    $this->actingAs($manager)->delete("/workspace/documents/{$document->id}/shares/{$share->id}");
    $this->actingAs($manager)->put("/workspace/documents/{$document->id}/sharing/organization", ['access' => null]);

    $this->actingAs($teammate)->postJson("/workspace/documents/{$document->id}/shares", ['member_id' => docsMemberId($this->colleague), 'permission' => 'edit'])->assertForbidden();

    $name = $this->colleague->name;
    expect(ProjectActivity::query()->where('project_id', $project->id)->where('subject_type', 'document')->orderBy('id')->pluck('description')->all())->toBe([
        "Shared document Plan with {$name} (can edit)",
        'Opened document Plan to everyone in SDOC (can view)',
        "Changed {$name}'s access to document Plan to can view",
        "Removed {$name}'s access to document Plan",
        'Closed document Plan to the organization',
    ]);

    // A personal document's sharing writes no activity.
    $this->actingAs($this->owner)->post("/workspace/documents/{$this->document->id}/shares", ['member_id' => docsMemberId($this->colleague), 'permission' => 'edit']);
    expect(ProjectActivity::query()->where('subject_type', 'document')->count())->toBe(5);
});

it('refuses sharing a frozen or trashed document', function () {
    $trashed = docsMake($this->owner, title: 'Old');
    $trashed->delete();
    $this->actingAs($this->owner)->postJson("/workspace/documents/{$trashed->id}/shares", ['member_id' => docsMemberId($this->colleague), 'permission' => 'edit'])->assertNotFound();

    $boss = User::factory()->create(['email' => 'boss@example.com']);
    docsUpdatePerson($this->owner, ['is_active' => false]);
    $this->actingAs($boss)->postJson("/workspace/documents/{$this->document->id}/shares", ['member_id' => docsMemberId($this->colleague), 'permission' => 'edit'])->assertForbidden();
    $this->actingAs($boss)->putJson("/workspace/documents/{$this->document->id}/sharing/organization", ['access' => 'view'])->assertForbidden();
});

it('offers candidates of the document\'s organization only, without emails', function () {
    $named = docsPerson($this->organization, null, null, ['name' => 'Sita Sharma']);
    docsPerson(Organization::factory()->create(), null, null, ['name' => 'Sita Outside']);
    docsPerson($this->organization, null, null, ['name' => 'Sita Away', 'is_active' => false]);
    $shared = docsPerson($this->organization, null, null, ['name' => 'Sita Shared']);
    docsShare($this->document, $shared, 'view');
    WorkspaceAccess::memberFor($this->owner)?->update(['name' => 'Sita Owner']);

    $response = $this->actingAs($this->owner)->getJson("/workspace/documents/{$this->document->id}/sharing/candidates?q=sita")->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['Sita Sharma'])
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'name', 'designation'])
        ->and($response->json('data.0.id'))->toBe(docsMemberId($named));
});

it('shares over the API too', function () {
    Sanctum::actingAs($this->owner);
    $base = "/api/v1/workspace/documents/{$this->document->id}";

    $this->postJson("{$base}/shares", ['member_id' => docsMemberId($this->colleague), 'permission' => 'edit'])->assertCreated()->assertJsonPath('data.permission', 'edit');
    $share = DocumentShare::query()->firstOrFail();
    $this->patchJson("{$base}/shares/{$share->id}", ['permission' => 'view'])->assertOk()->assertJsonPath('data.permission', 'view');
    $this->putJson("{$base}/sharing/organization", ['access' => 'edit'])->assertOk()->assertJsonPath('data.organization_access', 'edit');
    $this->getJson("{$base}/sharing")->assertOk()->assertJsonPath('data.shares.0.permission', 'view');
    $this->getJson("{$base}/sharing/candidates?q=")->assertOk();
    $this->deleteJson("{$base}/shares/{$share->id}")->assertOk();

    expect(Document::query()->find($this->document->id)?->organization_access)->toBe('edit')
        ->and(DocumentShare::query()->count())->toBe(0);
});
