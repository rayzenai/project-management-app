<?php

use App\Console\Commands\SendEmailUpdates;
use App\Models\Document;
use App\Models\Form;
use App\Models\FormFile;
use App\Models\FormResponse;
use App\Models\FormShare;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\User;
use App\Notifications\FormOpened;
use App\Notifications\FormReminder;
use App\Notifications\TaskAssigned;
use App\Support\WorkspaceAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * Forms, the internal round: building, sharing, filling in, responses and
 * tasks from them (docs/superpowers/specs/2026-11-07-forms-design.md).
 */

beforeEach(function () {
    config([
        'project-management.super_admins' => ['boss@example.com'],
        'forms.enabled' => true,
        'documents.enabled' => true,
        'documents.server_url' => 'https://docs.example',
        'documents.jwt_secret' => DOCS_SECRET,
    ]);
    Storage::fake('documents');

    $this->office = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->project = Project::factory()->create(['organization_id' => $this->office->id, 'title' => 'Surveys']);
    $this->manager = docsPerson($this->office, $this->project, 'leader');
    $this->maker = docsPerson($this->office, $this->project, 'member');
    $this->teammate = docsPerson($this->office, $this->project, 'member');
    $this->colleague = docsPerson($this->office, null);
    $this->admin = docsPerson($this->office, null, null, ['is_office_admin' => true]);
    $this->ministry = Organization::factory()->create(['name' => 'Ministry of Health', 'slug' => 'moh']);
    $this->outsider = docsPerson($this->ministry, null);
});

function formsMemberId(User $user): int
{
    return (int) WorkspaceAccess::memberFor($user)?->id;
}

/** A form made through the builder's route, with questions given as blocks of its one step. */
function formsMake(Project $project, User $maker, array $blocks = [], array $settings = []): Form
{
    test()->actingAs($maker)->post('/workspace/forms', ['project' => $project->slug, 'title' => 'Staff survey'])->assertRedirect();
    $form = Form::query()->latest('id')->firstOrFail();

    if ($blocks !== []) {
        test()->actingAs($maker)->putJson("/workspace/forms/{$form->id}/draft", ['draft' => [
            'languages' => ['en'],
            'steps' => [['id' => $form->draft['steps'][0]['id'], 'title' => ['en' => 'One'], 'blocks' => $blocks]],
        ]])->assertOk();
    }

    if ($settings !== []) {
        test()->actingAs($maker)->patchJson("/workspace/forms/{$form->id}", $settings)->assertOk();
    }

    return $form->refresh();
}

function formsPublish(Form $form, User $user, bool $notify = false): Form
{
    test()->actingAs($user)->postJson("/workspace/forms/{$form->id}/publish", ['notify' => $notify])->assertOk();

    return $form->refresh();
}

function formsQuestions(): array
{
    return [
        ['id' => 'q_feeling01', 'type' => 'yes_no', 'label' => ['en' => 'Happy at work?'], 'required' => true],
        ['id' => 'q_whyyy0001', 'type' => 'paragraph', 'label' => ['en' => 'Why not?'], 'required' => true, 'show_if' => ['conditions' => [['question' => 'q_feeling01', 'op' => 'equals', 'value' => false]]]],
        ['id' => 'q_office001', 'type' => 'short_text', 'label' => ['en' => 'Section'], 'required' => false],
    ];
}

function formsSubmit(Form $form, User $user, array $answers)
{
    return test()->actingAs($user)->postJson("/workspace/forms/{$form->id}/fill", ['answers' => $answers]);
}

it('builds a form from a template as a draft its maker can edit, and publishes it', function () {
    $this->actingAs($this->maker)
        ->post('/workspace/forms', ['project' => $this->project->slug, 'title' => 'Progress report', 'template' => 'data_call'])
        ->assertRedirect();

    $form = Form::query()->sole();
    expect($form->status)->toBe('draft')
        ->and($form->answering)->toBe('organization')
        ->and($form->created_by)->toBe($this->maker->id)
        ->and(strlen($form->code))->toBe(8)
        ->and(WorkspaceAccess::formAccess($this->maker, $form))->toBe('edit')
        ->and(WorkspaceAccess::formAccess($this->manager, $form))->toBe('edit')
        ->and(WorkspaceAccess::formAccess($this->admin, $form))->toBe('edit')
        ->and(WorkspaceAccess::formAccess($this->teammate, $form))->toBeNull()
        ->and(WorkspaceAccess::formAccess($this->colleague, $form))->toBeNull();

    $this->actingAs($this->maker)->get("/workspace/forms/{$form->id}/edit")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Forms/Edit')->where('form.title', 'Progress report')->where('form.published', false));

    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/publish")->assertOk()
        ->assertJsonPath('data.form.published', true);
    expect($form->refresh()->status)->toBe('open')->and($form->versions()->count())->toBe(1);

    // Nothing changed: no second version.
    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/publish")->assertStatus(422);
});

it('refuses a project the person doesn\'t work on, and a teammate builds nothing they didn\'t make', function () {
    $other = Project::factory()->create(['organization_id' => $this->office->id]);

    $this->actingAs($this->colleague)->post('/workspace/forms', ['project' => $this->project->slug, 'title' => 'X'])->assertSessionHasErrors('project');
    $this->actingAs($this->maker)->post('/workspace/forms', ['project' => $other->slug, 'title' => 'X'])->assertSessionHasErrors('project');
    $this->actingAs($this->outsider)->post('/workspace/forms', ['project' => $this->project->slug, 'title' => 'X'])->assertSessionHasErrors('project');

    $form = formsMake($this->project, $this->maker);
    $this->actingAs($this->teammate)->get("/workspace/forms/{$form->id}/edit")->assertForbidden();
    $this->actingAs($this->teammate)->putJson("/workspace/forms/{$form->id}/draft", ['draft' => $form->draft])->assertForbidden();
    $this->actingAs($this->teammate)->get("/workspace/forms/{$form->id}/responses")->assertForbidden();
});

it('keeps the live version as published while the draft changes, and refuses a question changing kind', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);
    $draft = $form->draft;
    $draft['steps'][0]['blocks'][2]['type'] = 'number';

    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/draft", ['draft' => $draft])->assertOk()
        ->assertJsonPath('data.changed', true);

    expect($form->refresh()->liveSchema()['steps'][0]['blocks'][2]['type'])->toBe('short_text');

    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/publish")->assertStatus(422)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Add a new question instead of changing its kind'));
});

it('lets a respondent answer, drops answers to hidden questions, and keeps one response each', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);

    formsSubmit($form, $this->colleague, ['q_feeling01' => false])->assertStatus(422)
        ->assertJsonPath('errors', ['answers.q_whyyy0001' => 'Answer this question.']);

    formsSubmit($form, $this->colleague, ['q_feeling01' => true, 'q_whyyy0001' => 'Left over', 'q_office001' => 'IT'])->assertOk();

    $response = FormResponse::query()->sole();
    expect($response->answers)->toEqual(['q_feeling01' => true, 'q_office001' => 'IT'])
        ->and($response->respondent_key)->toBe('m:'.formsMemberId($this->colleague))
        ->and($response->sender['name'])->toBe($this->colleague->name)
        ->and($response->stage)->toBe('new');

    // Editing replaces it, with its history.
    formsSubmit($form, $this->colleague, ['q_feeling01' => true, 'q_office001' => 'Admin'])->assertOk();
    expect(FormResponse::query()->count())->toBe(1)
        ->and($response->refresh()->answers['q_office001'])->toBe('Admin')
        ->and($response->events()->pluck('kind')->all())->toBe(['edited', 'submitted']);

    // Editing off: the second Submit is refused.
    $this->actingAs($this->maker)->patchJson("/workspace/forms/{$form->id}", ['allow_edit' => false])->assertOk();
    formsSubmit($form, $this->colleague, ['q_feeling01' => true])->assertStatus(422);
});

it('saves a draft unchecked, shows it again, and clears it on Submit or on request', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);

    $this->actingAs($this->colleague)->putJson("/workspace/forms/{$form->id}/fill/draft", ['answers' => ['q_office001' => 'half typ', 'q_nothere1' => 'x']])->assertOk();

    $this->actingAs($this->colleague)->get("/f/{$form->code}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Forms/Fill')->where('state', 'open')->where('answers', ['q_office001' => 'half typ']));

    $this->actingAs($this->colleague)->deleteJson("/workspace/forms/{$form->id}/fill/draft")->assertOk();
    expect($form->drafts()->count())->toBe(0);
});

it('shows someone it isn\'t for nothing about the form, and refuses their answers', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);

    $this->actingAs($this->outsider)->get("/f/{$form->code}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Forms/Fill')->where('state', 'not_for_you')->where('form', null));
    $this->actingAs($this->outsider)->get('/f/NOSUCHCO')->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'not_for_you'));
    formsSubmit($form, $this->outsider, ['q_feeling01' => true])->assertForbidden();

    // Inactive: no answering either.
    Member::query()->whereKey(formsMemberId($this->colleague))->update(['is_active' => false]);
    WorkspaceAccess::flush();
    expect(WorkspaceAccess::isFormRespondent($this->colleague, $form))->toBeFalse();
});

it('limits respondents to the team or chosen people, and only an admin sends it to another organization', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);

    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/respondents", ['respondents' => 'team', 'member_ids' => []])->assertOk();
    expect(WorkspaceAccess::isFormRespondent($this->teammate, $form->refresh()))->toBeTrue()
        ->and(WorkspaceAccess::isFormRespondent($this->colleague, $form))->toBeFalse();

    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/respondents", ['respondents' => 'people', 'member_ids' => [formsMemberId($this->outsider)]])
        ->assertStatus(422)->assertJsonPath('errors.member_ids.0', 'Choose people of SDOC.');
    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/respondents", ['respondents' => 'people', 'member_ids' => [formsMemberId($this->colleague)]])->assertOk();
    expect(WorkspaceAccess::isFormRespondent($this->colleague, $form->refresh()))->toBeTrue()
        ->and(WorkspaceAccess::isFormRespondent($this->teammate, $form))->toBeFalse();

    // The project's manager is no admin: another organization is refused.
    $this->actingAs($this->manager)->putJson("/workspace/forms/{$form->id}/respondents", ['respondents' => 'everyone', 'organization_ids' => [$this->ministry->id]])->assertForbidden();
    $this->actingAs($this->admin)->putJson("/workspace/forms/{$form->id}/respondents", ['respondents' => 'everyone', 'organization_ids' => [$this->ministry->id]])->assertOk();

    expect(WorkspaceAccess::isFormRespondent($this->outsider, $form->refresh()))->toBeTrue()
        ->and(Form::query()->answerableBy($this->outsider)->pluck('id')->all())->toBe([$form->id]);

    formsSubmit($form, $this->outsider, ['q_feeling01' => true])->assertOk();

    // Other organizations' people are counted, never named.
    $pending = $this->actingAs($this->maker)->getJson("/workspace/forms/{$form->id}/pending")->assertOk()->json('data');
    expect($pending['organizations'])->toBe([['id' => $this->ministry->id, 'name' => 'Ministry of Health', 'answered' => 1, 'total' => 1]])
        ->and(collect($pending['people'])->pluck('name'))->not->toContain($this->outsider->name);
});

it('takes one response per organization for a data call, which anyone there may change', function () {
    $form = formsMake($this->project, $this->maker, formsQuestions(), ['answering' => 'organization']);
    $this->actingAs($this->admin)->putJson("/workspace/forms/{$form->id}/respondents", ['respondents' => 'everyone', 'organization_ids' => [$this->ministry->id]])->assertOk();
    formsPublish($form, $this->maker);
    $second = docsPerson($this->ministry, null);

    formsSubmit($form, $this->outsider, ['q_feeling01' => true, 'q_office001' => 'First'])->assertOk();
    formsSubmit($form, $second, ['q_feeling01' => true, 'q_office001' => 'Second'])->assertOk();

    $response = FormResponse::query()->where('organization_id', $this->ministry->id)->sole();
    expect($response->respondent_key)->toBe('o:'.$this->ministry->id)
        ->and($response->answers['q_office001'])->toBe('Second')
        ->and($response->updated_by)->toBe($second->id);

    // Who answers can't change once responses are in.
    $this->actingAs($this->maker)->patchJson("/workspace/forms/{$form->id}", ['answering' => 'person'])->assertStatus(422);

    $pending = $this->actingAs($this->maker)->getJson("/workspace/forms/{$form->id}/pending")->json('data');
    expect($pending['mode'])->toBe('organization')->and(collect($pending['organizations'])->pluck('name')->all())->toBe(['SDOC']);
});

it('refuses answers once the form is closed or past its close date', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);

    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/close")->assertOk();
    formsSubmit($form, $this->colleague, ['q_feeling01' => true])->assertForbidden();

    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/reopen")->assertOk();
    formsSubmit($form, $this->colleague, ['q_feeling01' => true])->assertOk();

    $this->actingAs($this->maker)->patchJson("/workspace/forms/{$form->id}", ['closes_at' => now()->subMinute()->toIso8601String(), 'opens_at' => now()->subDay()->toIso8601String()])->assertOk();
    formsSubmit($form, $this->colleague, ['q_feeling01' => false, 'q_whyyy0001' => 'Late'])->assertForbidden();
});

it('shares a form inside its organization only, and Can see responses sees but never edits', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);

    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/shares", ['member_id' => formsMemberId($this->outsider), 'permission' => 'responses'])
        ->assertStatus(422)->assertJsonPath('errors.member_id.0', 'Choose someone in SDOC.');
    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/shares", ['member_id' => 999999, 'permission' => 'responses'])
        ->assertStatus(422)->assertJsonPath('errors.member_id.0', 'Choose someone in SDOC.');
    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/shares", ['member_id' => formsMemberId($this->colleague), 'permission' => 'responses'])->assertCreated();

    expect(WorkspaceAccess::formAccess($this->colleague, $form))->toBe('responses');
    $this->actingAs($this->colleague)->get("/workspace/forms/{$form->id}/responses")->assertOk();
    $this->actingAs($this->colleague)->get("/workspace/forms/{$form->id}/edit")->assertForbidden();
    $this->actingAs($this->colleague)->postJson("/workspace/forms/{$form->id}/close")->assertForbidden();
    $this->actingAs($this->colleague)->postJson("/workspace/forms/{$form->id}/shares", ['member_id' => formsMemberId($this->teammate), 'permission' => 'edit'])->assertForbidden();

    // A share stops counting when its person moves away.
    Member::query()->whereKey(formsMemberId($this->colleague))->update(['organization_id' => $this->ministry->id]);
    WorkspaceAccess::flush();
    expect(WorkspaceAccess::formAccess($this->colleague->fresh(), $form))->toBeNull();
});

it('agrees with its query twins on who manages and who answers', function () {
    $form = formsMake($this->project, $this->maker);
    $other = formsMake(Project::factory()->create(['organization_id' => $this->ministry->id]), docsPerson($this->ministry, null, null, ['is_office_admin' => true]));
    FormShare::query()->create(['form_id' => $form->id, 'member_id' => formsMemberId($this->colleague), 'permission' => 'responses']);
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    foreach ([$this->manager, $this->maker, $this->teammate, $this->colleague, $this->admin, $this->outsider, $boss] as $user) {
        WorkspaceAccess::flush();
        $twin = Form::query()->managedBy($user)->pluck('id')->sort()->values()->all();
        $level = collect([$form, $other])->filter(fn (Form $f): bool => WorkspaceAccess::formAccess($user, $f) !== null)->pluck('id')->sort()->values()->all();
        expect($twin)->toBe($level);

        $answers = Form::query()->answerableBy($user)->pluck('id')->sort()->values()->all();
        $respondent = collect([$form, $other])->filter(fn (Form $f): bool => WorkspaceAccess::isFormRespondent($user, $f))->pluck('id')->sort()->values()->all();
        expect($answers)->toBe($respondent);
    }
});

it('tells chosen people on the first publish, and reminds them once a day', function () {
    $form = formsMake($this->project, $this->maker, formsQuestions());
    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/respondents", ['respondents' => 'people', 'member_ids' => [formsMemberId($this->colleague), formsMemberId($this->teammate)]])->assertOk();
    formsPublish($form, $this->maker, notify: true);

    $note = $this->colleague->notifications()->sole();
    expect($note->type)->toBe(FormOpened::class)
        ->and($note->data['url'])->toBe("/f/{$form->code}")
        ->and(SendEmailUpdates::item($note->data)['title'])->toBe('Please fill in: Staff survey')
        ->and(in_array(FormOpened::class, SendEmailUpdates::KINDS, true))->toBeTrue()
        ->and($this->maker->notifications()->where('type', FormOpened::class)->count())->toBe(0);

    formsSubmit($form, $this->teammate, ['q_feeling01' => true])->assertOk();
    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/remind")->assertOk()->assertJsonPath('data.sent', 1);
    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/remind")->assertOk()->assertJsonPath('data.sent', 0);

    expect($this->colleague->notifications()->where('type', FormReminder::class)->count())->toBe(1)
        ->and($this->teammate->notifications()->where('type', FormReminder::class)->count())->toBe(0);
});

it('makes a task from each new response only when the form turns it on, by the system', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);
    formsSubmit($form, $this->colleague, ['q_feeling01' => true, 'q_office001' => 'Before'])->assertOk();
    expect(Task::query()->count())->toBe(0);

    $elsewhere = Project::factory()->create(['organization_id' => $this->ministry->id]);
    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/tasks", ['enabled' => true, 'project' => $elsewhere->slug, 'assignee_id' => formsMemberId($this->teammate)])->assertStatus(422);
    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/tasks", ['enabled' => true, 'project' => $this->project->slug, 'assignee_id' => formsMemberId($this->outsider)])
        ->assertStatus(422)->assertJsonPath('errors.assignee_id.0', 'Choose someone who can be given tasks in Surveys.');
    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/tasks", [
        'enabled' => true, 'project' => $this->project->slug, 'assignee_id' => formsMemberId($this->teammate), 'due_days' => 3, 'copy_questions' => ['q_office001'],
    ])->assertOk();

    expect(Task::query()->count())->toBe(0);

    formsSubmit($form, $this->admin, ['q_feeling01' => false, 'q_whyyy0001' => 'Private reason', 'q_office001' => 'Accounts'])->assertOk();

    $task = Task::query()->sole();
    $response = FormResponse::query()->where('task_id', $task->id)->sole();
    expect($task->created_by)->toBeNull()
        ->and($task->project_id)->toBe($this->project->id)
        ->and($task->title)->toBe('Staff survey: '.$response->reference)
        ->and($task->description)->toContain('Section: Accounts')
        ->and($task->description)->not->toContain('Private reason')
        ->and($task->deadline_at?->toDateString())->toBe(now()->addDays(3)->toDateString())
        ->and($task->assignments()->pluck('member_id')->all())->toBe([formsMemberId($this->teammate)])
        ->and(ProjectActivity::query()->where('task_id', $task->id)->whereNotNull('user_id')->count())->toBe(0)
        ->and($this->teammate->notifications()->where('type', TaskAssigned::class)->count())->toBe(1);

    // An edit makes no second task; a test makes none.
    formsSubmit($form, $this->admin, ['q_feeling01' => true])->assertOk();
    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/fill", ['answers' => ['q_feeling01' => true], 'test' => true])->assertOk()->assertJsonPath('data.test', true);
    expect(Task::query()->count())->toBe(1)->and(FormResponse::query()->count())->toBe(2);
});

it('keeps answer files to their respondent and the form\'s side', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, [
        ['id' => 'q_proof0001', 'type' => 'file', 'label' => ['en' => 'Proof'], 'required' => true, 'rules' => ['kinds' => ['pdf'], 'max_files' => 1]],
    ]), $this->maker);

    $this->actingAs($this->colleague)->post("/workspace/forms/{$form->id}/files", ['purpose' => 'answer', 'block' => 'q_proof0001', 'file' => UploadedFile::fake()->create('photo.png', 10)], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.file.0', 'Add a PDF file.');
    $id = $this->actingAs($this->colleague)->post("/workspace/forms/{$form->id}/files", ['purpose' => 'answer', 'block' => 'q_proof0001', 'file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    // Someone else's file id is refused as theirs.
    formsSubmit($form, $this->teammate, ['q_proof0001' => [$id]])->assertStatus(422)->assertJsonPath('errors', ['answers.q_proof0001' => 'A file is missing. Add it again.']);
    formsSubmit($form, $this->colleague, ['q_proof0001' => [$id]])->assertOk();

    $file = FormFile::query()->findOrFail($id);
    expect($file->form_response_id)->toBe(FormResponse::query()->sole()->id);
    $this->actingAs($this->colleague)->get("/workspace/forms/files/{$id}")->assertOk();
    $this->actingAs($this->maker)->get("/workspace/forms/files/{$id}")->assertOk();
    $this->actingAs($this->teammate)->get("/workspace/forms/files/{$id}")->assertNotFound();
    $this->actingAs($this->maker)->get("/workspace/forms/{$form->id}/responses/files")->assertOk();
});

it('exports responses as a download and as a personal document, never a project one', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);
    formsSubmit($form, $this->colleague, ['q_feeling01' => true, 'q_office001' => 'IT'])->assertOk();

    $this->actingAs($this->maker)->get("/workspace/forms/{$form->id}/responses/export")->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($this->maker)->post("/workspace/forms/{$form->id}/responses/spreadsheet")->assertRedirect();
    $document = Document::query()->sole();
    expect($document->project_id)->toBeNull()
        ->and($document->owner_id)->toBe($this->maker->id)
        ->and($document->file_type)->toBe('xlsx')
        ->and(WorkspaceAccess::documentAccess($this->teammate, $document))->toBeNull();

    $this->actingAs($this->teammate)->get("/workspace/forms/{$form->id}/responses/export")->assertForbidden();
});

it('lists, filters and moves responses between stages with a history', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);
    formsSubmit($form, $this->colleague, ['q_feeling01' => true])->assertOk();
    formsSubmit($form, $this->teammate, ['q_feeling01' => false, 'q_whyyy0001' => 'Hours'])->assertOk();

    $this->actingAs($this->maker)->get("/workspace/forms/{$form->id}/responses")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Forms/Responses')->where('page.total', 2));
    $this->actingAs($this->maker)->getJson("/workspace/forms/{$form->id}/responses/list?question=q_feeling01&answer=no")->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.sender.name', $this->teammate->name);

    $ids = FormResponse::query()->pluck('id')->all();
    $this->actingAs($this->maker)->patchJson("/workspace/forms/{$form->id}/responses/stage", ['ids' => $ids, 'stage' => 'nowhere'])->assertStatus(422);
    $this->actingAs($this->maker)->patchJson("/workspace/forms/{$form->id}/responses/stage", ['ids' => $ids, 'stage' => 'done', 'note' => 'Read'])->assertOk()->assertJsonPath('data.moved', 2);

    $this->actingAs($this->maker)->postJson("/workspace/forms/{$form->id}/responses/{$ids[0]}/notes", ['text' => 'Call them'])->assertCreated();
    $this->actingAs($this->maker)->getJson("/workspace/forms/{$form->id}/responses/{$ids[0]}")->assertOk()
        ->assertJsonPath('data.history.0.text', 'Note')
        ->assertJsonPath('data.history.0.note', 'Call them')
        ->assertJsonPath('data.history.1.text', 'Moved from New to Done');

    $this->actingAs($this->maker)->getJson("/workspace/forms/{$form->id}/summary")->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.questions.0.counts.0.count', 1);
});

it('deletes only a form nobody has answered', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);
    formsSubmit($form, $this->colleague, ['q_feeling01' => true])->assertOk();

    $this->actingAs($this->maker)->delete("/workspace/forms/{$form->id}")->assertSessionHasErrors();
    expect($form->refresh()->trashed())->toBeFalse();

    $empty = formsMake($this->project, $this->maker);
    $this->actingAs($this->maker)->delete("/workspace/forms/{$empty->id}")->assertRedirect('/workspace/forms');
    expect($empty->refresh()->trashed())->toBeTrue();
});

it('shows the Forms page\'s three lists', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);

    $this->actingAs($this->colleague)->get('/workspace/forms')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Forms/Index')->where('to_fill.0.code', $form->code)->where('filled', [])->where('managing', []));
    $this->actingAs($this->colleague)->get('/workspace/my')->assertOk()
        ->assertInertia(fn ($page) => $page->component('MyWork')->where('forms_to_fill.0.code', $form->code));

    formsSubmit($form, $this->colleague, ['q_feeling01' => true])->assertOk();

    $this->actingAs($this->colleague)->get('/workspace/forms')->assertOk()
        ->assertInertia(fn ($page) => $page->where('to_fill', [])->where('filled.0.code', $form->code));
    $this->actingAs($this->colleague)->get('/workspace/my')->assertOk()
        ->assertInertia(fn ($page) => $page->where('forms_to_fill', []));
    $this->actingAs($this->maker)->get('/workspace/forms')->assertOk()
        ->assertInertia(fn ($page) => $page->where('managing.0.code', $form->code)->where('managing.0.responses_count', 1));
});

it('hides everything while forms are off', function () {
    $form = formsMake($this->project, $this->maker);
    config(['forms.enabled' => false]);

    $this->actingAs($this->maker)->get('/workspace/forms')->assertNotFound();
    $this->actingAs($this->maker)->get("/workspace/forms/{$form->id}/edit")->assertForbidden();
    expect(WorkspaceAccess::formAccess($this->maker, $form))->toBeNull();
    Sanctum::actingAs($this->maker);
});

it('changes and removes a share, and only the form\'s own', function () {
    $form = formsMake($this->project, $this->maker);
    $other = formsMake($this->project, $this->maker);
    $share = FormShare::query()->create(['form_id' => $other->id, 'member_id' => formsMemberId($this->colleague), 'permission' => 'responses']);

    $this->actingAs($this->maker)->patchJson("/workspace/forms/{$form->id}/shares/{$share->id}", ['permission' => 'edit'])->assertNotFound();
    $this->actingAs($this->maker)->patchJson("/workspace/forms/{$other->id}/shares/{$share->id}", ['permission' => 'edit'])->assertOk()->assertJsonPath('data.permission', 'edit');
    $this->actingAs($this->maker)->deleteJson("/workspace/forms/{$other->id}/shares/{$share->id}")->assertOk();
    expect(FormShare::query()->count())->toBe(0);
});

it('closes a form when its project is archived', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);
    $this->project->archive();

    expect($form->refresh()->isOpenNow())->toBeFalse()->and($form->phase())->toBe('closed');
    formsSubmit($form, $this->colleague, ['q_feeling01' => true])->assertForbidden();
    $this->actingAs($this->colleague)->get("/f/{$form->code}")->assertInertia(fn ($page) => $page->where('state', 'closed'));
});

it('never names someone else\'s file to a respondent, whatever their draft holds', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, [
        ['id' => 'q_proof0001', 'type' => 'file', 'label' => ['en' => 'Proof'], 'rules' => ['kinds' => ['pdf'], 'max_files' => 3]],
    ]), $this->maker);
    $theirs = $this->actingAs($this->teammate)->post("/workspace/forms/{$form->id}/files", ['purpose' => 'answer', 'block' => 'q_proof0001', 'file' => UploadedFile::fake()->create('citizenship-teammate.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $mine = $this->actingAs($this->colleague)->post("/workspace/forms/{$form->id}/files", ['purpose' => 'answer', 'block' => 'q_proof0001', 'file' => UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    $this->actingAs($this->colleague)->putJson("/workspace/forms/{$form->id}/fill/draft", ['answers' => ['q_proof0001' => [$theirs, $mine]]])->assertOk();

    $this->actingAs($this->colleague)->get("/f/{$form->code}")->assertOk()
        ->assertInertia(fn ($page) => $page->has("files.{$mine}")->missing("files.{$theirs}"));
});

it('lets someone who only answers open the live version\'s pictures, not a draft\'s', function () {
    $form = formsPublish(formsMake($this->project, $this->maker, formsQuestions()), $this->maker);
    $id = $this->actingAs($this->maker)->post("/workspace/forms/{$form->id}/files", ['purpose' => 'content', 'block' => 'q_picture01', 'kind' => 'image', 'file' => UploadedFile::fake()->image('circular.png')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    $this->actingAs($this->maker)->get("/workspace/forms/files/{$id}")->assertOk();
    $this->actingAs($this->colleague)->get("/workspace/forms/files/{$id}")->assertNotFound();

    $draft = $form->refresh()->draft;
    $draft['steps'][0]['blocks'][] = ['id' => 'q_picture01', 'type' => 'image', 'file' => $id, 'alt' => ['en' => 'The circular']];
    $this->actingAs($this->maker)->putJson("/workspace/forms/{$form->id}/draft", ['draft' => $draft])->assertOk();
    $this->actingAs($this->colleague)->get("/workspace/forms/files/{$id}")->assertNotFound();

    formsPublish($form, $this->maker);
    $this->actingAs($this->colleague)->get("/workspace/forms/files/{$id}")->assertOk();
});

it('shows another organization\'s respondents the office running it, not its project or people', function () {
    $form = formsMake($this->project, $this->maker, formsQuestions());
    $this->actingAs($this->admin)->putJson("/workspace/forms/{$form->id}/respondents", ['respondents' => 'everyone', 'organization_ids' => [$this->ministry->id]])->assertOk();
    formsPublish($form, $this->maker);

    $this->actingAs($this->outsider)->get("/f/{$form->code}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('form.organization', 'SDOC')->where('form.project', null)->where('form.maker', null));
    $this->actingAs($this->outsider)->get('/workspace/forms')->assertOk()
        ->assertInertia(fn ($page) => $page->where('to_fill.0.project', null)->where('to_fill.0.organization', 'SDOC'));
    $this->actingAs($this->colleague)->get("/f/{$form->code}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('form.project', 'Surveys'));
});
