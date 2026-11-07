<?php

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request about the form in the route. Subclasses say who may make it
 * ({@see WorkspaceAccess}'s Forms section) and what it carries.
 */
abstract class FormRouteRequest extends FormRequest
{
    public function form(): Form
    {
        $form = $this->route('form');
        abort_unless($form instanceof Form, 404);

        return $form;
    }

    /** The signed-in person; the requests that need one refuse without. */
    public function member(): Member
    {
        $member = WorkspaceAccess::memberFor($this->user());
        abort_unless($member !== null, 403);

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
