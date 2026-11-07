<?php

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Give a form to one person (store) or change their level (update): an
 * active person of its project's organization
 * ({@see WorkspaceAccess::canShareFormWith()}). Anyone else, or an unknown
 * id, gets the same message, which names nobody.
 */
class FormShareRequest extends EditFormRequest
{
    private ?Member $person = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'bail', 'integer', $this->inOrganization()],
            'permission' => ['required', Rule::in(Form::ACCESSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->person !== null && ! WorkspaceAccess::canShareFormWith($this->form(), $this->person)) {
                $validator->errors()->add('member_id', $this->choose());
            }
        });
    }

    public function person(): Member
    {
        return $this->person ?? Member::query()->findOrFail((int) $this->validated('member_id'));
    }

    private function choose(): string
    {
        return 'Choose someone in '.($this->form()->project->organization->name ?? 'the organization').'.';
    }

    private function inOrganization(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $this->person = Member::query()->whereKey((int) $value)->where('organization_id', $this->form()->project->organization_id)->first();

            if ($this->person === null) {
                $fail($this->choose());
            }
        };
    }
}
