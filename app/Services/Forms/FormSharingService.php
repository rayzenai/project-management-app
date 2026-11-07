<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormShare;
use App\Models\Member;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Chosen people who build a form (Can edit) or handle its responses (Can see
 * responses), inside its project's organization. The requests have checked
 * canEditForm and whom it goes to.
 */
class FormSharingService
{
    public function share(Form $form, Member $member, string $permission, Authenticatable $user): ServiceResult
    {
        $share = FormShare::query()->firstOrCreate(
            ['form_id' => $form->id, 'member_id' => $member->id],
            ['permission' => $permission, 'shared_by' => (int) $user->getAuthIdentifier()],
        );

        if ($share->permission !== $permission) {
            $share->permission = $permission;
            $share->save();
        }

        return ServiceResult::success($share->load('member.designation'), "Shared {$form->title} with {$member->name}.");
    }

    public function changePermission(FormShare $share, string $permission): ServiceResult
    {
        $share->permission = $permission;
        $share->save();

        $can = $permission === Form::ACCESS_EDIT ? 'edit' : 'see the responses of';

        return ServiceResult::success($share->load('member.designation'), "{$share->member->name} can now {$can} {$share->form->title}.");
    }

    public function unshare(FormShare $share): ServiceResult
    {
        $share->delete();

        return ServiceResult::success(null, "{$share->member->name} no longer has {$share->form->title}.");
    }
}
