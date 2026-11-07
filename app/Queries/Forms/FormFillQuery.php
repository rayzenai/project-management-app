<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\FormDraft;
use App\Models\FormResponse;
use App\Models\Member;
use App\Services\Forms\FormFillService;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The page a respondent fills a form in on, or reads their response on:
 * who runs the form, its questions (the live version; the draft for the
 * form's side trying it), who they are answering as, their draft or
 * response, and why they can't answer when they can't.
 */
class FormFillQuery
{
    /**
     * @return array<string, mixed>
     */
    public function for(Form $form, Authenticatable $viewer, bool $test): array
    {
        $form->loadMissing(['project.program', 'project.organization', 'creator', 'publishedVersion']);
        $member = WorkspaceAccess::memberFor($viewer);
        $member?->loadMissing(['designation:id,name', 'organization:id,name']);
        $key = $member === null ? null : FormFillService::respondentKey($form, $member, $test);
        $response = $test || $key === null ? null : FormResponse::query()->where('form_id', $form->id)->where('respondent_key', $key)->with(['updater:id,name', 'submitter:id,name'])->first();
        $draft = $key === null ? null : FormDraft::query()->where('form_id', $form->id)->where('respondent_key', $key)->with('updater:id,name')->first();
        $schema = FormFillService::schemaFor($form, $test);
        $answers = $draft->answers ?? $response->answers ?? [];
        $fileIds = FormFillService::fileIds($schema, $answers);
        // Someone of another organization sees which office runs the form,
        // and nothing else of it: not its project, not its people.
        $inside = WorkspaceAccess::formAccess($viewer, $form) !== null
            || ($member !== null && (int) $member->organization_id === (int) $form->project->organization_id);

        return [
            'form' => [
                'id' => $form->id,
                'code' => $form->code,
                'title' => $form->title,
                'organization' => $form->project->organization?->name,
                'project' => $inside ? $form->project->label() : null,
                'maker' => $inside ? $form->creator?->name : null,
                'closes_at' => $form->closes_at?->toIso8601String(),
                'opens_at' => $form->opens_at?->toIso8601String(),
                'answering' => $form->answering,
                'allow_edit' => (bool) $form->setting('allow_edit', true),
                'review_page' => (bool) $form->setting('review_page', true),
                'can_manage' => WorkspaceAccess::formAccess($viewer, $form) !== null,
            ],
            'test' => $test,
            'state' => $test ? 'open' : $this->state($form, $viewer, $response),
            'schema' => $schema,
            'respondent' => $member === null ? null : [
                'name' => $member->name,
                'designation' => $member->designation?->name,
                'organization' => $member->organization?->name,
            ],
            // Objects, never `[]`, even when empty: the page adds answers by question id.
            'answers' => (object) $answers,
            'draft' => $draft === null ? null : [
                'saved_at' => $draft->updated_at?->toIso8601String(),
                'saved_by' => $draft->updater?->name,
            ],
            'response' => $response === null ? null : [
                'reference' => $response->reference,
                'submitted_at' => $response->submitted_at->toIso8601String(),
                'updated_at' => $response->updated_at?->toIso8601String(),
                'updated_by' => $response->updater?->name,
                'answers' => (object) $response->answers,
            ],
            'files' => (object) (FormFileRefs::content($form->id, $schema) + ($key === null ? [] : FormFileRefs::own($form->id, $fileIds, $key, $response?->id))),
            'max_upload_mb' => (int) config('forms.max_upload_mb', 20),
        ];
    }

    /**
     * open, not_open_yet, closed, full, sent (sent, and editing is off or
     * over) or not_for_you.
     */
    private function state(Form $form, Authenticatable $viewer, ?FormResponse $response): string
    {
        if (! WorkspaceAccess::isFormRespondent($viewer, $form)) {
            return 'not_for_you';
        }

        if ($form->published_version_id === null || $form->status === Form::STATUS_DRAFT) {
            return 'not_open_yet';
        }

        if ($form->status === Form::STATUS_CLOSED || $form->project->archived_at !== null || ($form->closes_at !== null && $form->closes_at->lte(CarbonImmutable::now()))) {
            return 'closed';
        }

        if ($form->opens_at !== null && $form->opens_at->gt(CarbonImmutable::now())) {
            return 'not_open_yet';
        }

        if ($response !== null) {
            return $form->setting('allow_edit', true) ? 'open' : 'sent';
        }

        if ($form->response_limit !== null && $form->countedResponses() >= $form->response_limit) {
            return 'full';
        }

        return 'open';
    }

    /** A person record, for the requests. */
    public static function member(Authenticatable $viewer): ?Member
    {
        return WorkspaceAccess::memberFor($viewer);
    }
}
