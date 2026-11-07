<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\FormResponse;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * How a form shows in a list (the Forms page, a project's tab, My work):
 * what it is, where it lives, its phase and dates, and what the viewer may
 * do with it.
 */
final class FormItems
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Form $form, ?Authenticatable $viewer, ?FormResponse $mine = null): array
    {
        $access = WorkspaceAccess::formAccess($viewer, $form);
        $project = $form->project;
        // Another organization's respondent learns the office, not its project.
        $member = WorkspaceAccess::memberFor($viewer);
        $inside = $access !== null || WorkspaceAccess::isSuperAdmin($viewer)
            || ($member !== null && (int) $member->organization_id === (int) $project->organization_id);

        return [
            'id' => $form->id,
            'code' => $form->code,
            'title' => $form->title,
            'phase' => $form->phase(),
            'opens_at' => $form->opens_at?->toIso8601String(),
            'closes_at' => $form->closes_at?->toIso8601String(),
            'answering' => $form->answering,
            'project' => $inside ? ['slug' => $project->slug, 'title' => $project->title, 'label' => $project->label()] : null,
            'organization' => $project->organization?->name,
            'access' => $access,
            'responses_count' => $access === null ? null : (int) ($form->getAttribute('counted_responses_count') ?? $form->countedResponses()),
            'mine' => $mine === null ? null : ['reference' => $mine->reference, 'submitted_at' => $mine->submitted_at->toIso8601String(), 'updated_at' => $mine->updated_at?->toIso8601String()],
            'updated_at' => $form->updated_at?->toIso8601String(),
            'link' => url("/f/{$form->code}"),
        ];
    }
}
