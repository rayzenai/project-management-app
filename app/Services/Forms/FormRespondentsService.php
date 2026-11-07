<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Support\ServiceResult;

/**
 * Who fills a form in: everyone in its project's organization, the project's
 * team, or chosen people of that organization, plus other organizations it
 * is sent to. The request has checked the people and, for other
 * organizations, canAddFormOrganizations.
 */
class FormRespondentsService
{
    /**
     * @param  list<int>  $memberIds  the chosen people (kept for "chosen people" only)
     * @param  list<int>|null  $organizationIds  null: unchanged
     */
    public function execute(Form $form, string $respondents, array $memberIds, ?array $organizationIds): ServiceResult
    {
        $form->respondents = $respondents;
        $form->save();

        $form->respondentMembers()->sync($respondents === Form::RESPONDENTS_PEOPLE ? $memberIds : []);

        if ($organizationIds !== null) {
            $own = (int) $form->project->organization_id;
            $form->respondentOrganizations()->sync(array_values(array_filter($organizationIds, fn (int $id): bool => $id !== $own)));
        }

        return ServiceResult::success($form, "Saved who fills in {$form->title}.");
    }
}
