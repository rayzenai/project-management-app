<?php

use App\Models\Document;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * Forms over the API, the twins of the web's
 * (docs/superpowers/specs/2026-11-07-forms-design.md).
 */

beforeEach(function () {
    config(['forms.enabled' => true, 'documents.enabled' => true, 'documents.server_url' => 'https://docs.example', 'documents.jwt_secret' => DOCS_SECRET]);
    Storage::fake('documents');

    $this->office = Organization::factory()->create(['name' => 'SDOC']);
    $this->project = Project::factory()->create(['organization_id' => $this->office->id]);
    $this->maker = docsPerson($this->office, $this->project, 'member');
    $this->colleague = docsPerson($this->office, null);
    $this->outsider = docsPerson(Organization::factory()->create(), null);
});

it('builds, publishes, fills in and reads a form over the API', function () {
    Sanctum::actingAs($this->maker);

    $id = $this->postJson('/api/v1/workspace/forms', ['project' => $this->project->slug, 'title' => 'Inventory', 'template' => 'request'])
        ->assertCreated()->assertJsonPath('data.title', 'Inventory')->json('data.id');
    $form = Form::query()->findOrFail($id);

    $this->getJson("/api/v1/workspace/forms/{$id}")->assertOk()->assertJsonPath('data.form.published', false);
    $this->putJson("/api/v1/workspace/forms/{$id}/draft", ['draft' => $form->draft])->assertOk()->assertJsonPath('data.changed', true);
    $this->postJson("/api/v1/workspace/forms/{$id}/publish")->assertOk()->assertJsonPath('data.form.published', true);

    Sanctum::actingAs($this->colleague);
    $first = $form->refresh()->liveSchema()['steps'][0]['blocks'];
    $this->getJson("/api/v1/workspace/forms/{$id}/fill")->assertOk()->assertJsonPath('data.state', 'open');
    $this->postJson("/api/v1/workspace/forms/{$id}/fill", ['answers' => [
        $first[0]['id'] => 'A laptop',
        $first[2]['id'] => $first[2]['options'][0]['id'],
    ]])->assertOk()->assertJsonPath('data.reference', FormResponse::query()->sole()->reference);
    $this->getJson('/api/v1/workspace/forms')->assertOk()->assertJsonPath('data.filled.0.id', $id);
    $this->getJson("/api/v1/workspace/forms/{$id}/responses")->assertForbidden();

    Sanctum::actingAs($this->maker);
    $this->getJson("/api/v1/workspace/forms/{$id}/responses")->assertOk()->assertJsonPath('data.page.total', 1);
    $this->postJson("/api/v1/workspace/forms/{$id}/responses/spreadsheet")->assertCreated();
    expect(Document::query()->sole()->owner_id)->toBe($this->maker->id);
});

it('refuses someone the form isn\'t for, over the API too', function () {
    Sanctum::actingAs($this->maker);
    $id = $this->postJson('/api/v1/workspace/forms', ['project' => $this->project->slug, 'title' => 'Inventory', 'template' => 'request'])->json('data.id');
    $this->postJson("/api/v1/workspace/forms/{$id}/publish")->assertOk();

    Sanctum::actingAs($this->outsider);
    $this->getJson("/api/v1/workspace/forms/{$id}/fill")->assertForbidden();
    $this->getJson("/api/v1/workspace/forms/{$id}")->assertForbidden();
    $this->postJson("/api/v1/workspace/forms/{$id}/fill", ['answers' => []])->assertForbidden();
    $this->postJson('/api/v1/workspace/forms', ['project' => $this->project->slug, 'title' => 'X'])->assertStatus(422);
});
