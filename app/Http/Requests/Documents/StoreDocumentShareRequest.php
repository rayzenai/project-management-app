<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Give a document to one person: someone of its organization (anyone else,
 * or an unknown id, gets the same message, which names nobody), active, and
 * not a personal document's owner ({@see WorkspaceAccess::canShareDocumentWith()}).
 */
class StoreDocumentShareRequest extends ShareDocumentRequest
{
    private ?Member $person = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['bail', 'required', 'integer', $this->inOrganization()],
            'permission' => ['required', Rule::in(Document::ACCESSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->person !== null && ! WorkspaceAccess::canShareDocumentWith($this->document(), $this->person)) {
                $validator->errors()->add('member_id', "You can't share this with {$this->person->name}.");
            }
        });
    }

    /** The person to share with, once the request has validated. */
    public function person(): Member
    {
        return $this->person ?? Member::query()->findOrFail((int) $this->validated('member_id'));
    }

    private function inOrganization(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $organizationId = WorkspaceAccess::documentOrganizationId($this->document());

            $this->person = $organizationId === null ? null : Member::query()
                ->whereKey((int) $value)
                ->where('organization_id', $organizationId)
                ->first();

            if ($this->person === null) {
                $fail('Choose someone in '.($this->organizationName() ?? 'your organization').'.');
            }
        };
    }
}
