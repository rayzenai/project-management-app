<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Program;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Whoever administers a program's organization renames it, rewrites its
 * description or hands it to another manager. The organization never changes.
 */
class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('program');

        return $program instanceof Program
            && WorkspaceAccess::canManagePrograms($this->user(), $program->organization_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'manager_id' => ['sometimes', 'required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $program = $this->route('program');

            if ($validator->errors()->isNotEmpty() || ! $program instanceof Program) {
                return;
            }

            if ($this->has('manager_id')) {
                $manager = Member::query()->find($this->integer('manager_id'));

                if ($manager === null || ! $manager->is_active || (int) $manager->organization_id !== (int) $program->organization_id) {
                    $validator->errors()->add('manager_id', "The program manager must be an active person of the program's organization.");
                }
            }

            if ($this->has('name') && Program::query()
                ->where('organization_id', $program->organization_id)
                ->where('name', $this->input('name'))
                ->whereKeyNot($program->getKey())
                ->exists()) {
                $validator->errors()->add('name', 'That name is already used in this organization.');
            }
        });
    }
}
