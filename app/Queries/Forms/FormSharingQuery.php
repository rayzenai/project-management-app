<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\FormShare;
use App\Queries\Structure\PersonRef;
use App\Support\WorkspaceAccess;

/**
 * Who has a form beyond running its project: the people it is shared with,
 * each Can edit or Can see responses, and whether that counts now.
 */
class FormSharingQuery
{
    /**
     * @return array{organization: array{name: string}|null, shares: list<array{id: int, member: array{id: int, name: string, designation: string|null}, permission: string, effective: bool}>}
     */
    public function for(Form $form): array
    {
        $organization = $form->project->organization;

        return [
            'organization' => $organization === null ? null : ['name' => $organization->name],
            'shares' => array_values($form->shares()->with('member.designation:id,name')->get()
                ->sortBy(fn (FormShare $share): string => mb_strtolower($share->member->name))
                ->map(fn (FormShare $share): array => self::share($share))
                ->all()),
        ];
    }

    /** @return array{id: int, member: array{id: int, name: string, designation: string|null}, permission: string, effective: bool} */
    public static function share(FormShare $share): array
    {
        return [
            'id' => $share->id,
            'member' => PersonRef::of($share->member),
            'permission' => $share->permission,
            'effective' => WorkspaceAccess::isFormShareEffective($share),
        ];
    }
}
