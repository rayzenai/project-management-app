<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Give a document to one person: an active person of its organization, not a
 * personal document's owner ({@see WorkspaceAccess::canShareDocumentWith()}).
 * Anyone else, or an unknown id, gets the same message, which names nobody.
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
                $validator->errors()->add('member_id', $this->choose());
            }
        });
    }

    /** The person to share with, once the request has validated. */
    public function person(): Member
    {
        return $this->person ?? Member::query()->findOrFail((int) $this->validated('member_id'));
    }

    /** The one refusal, whoever was named: it never says who they are. */
    private function choose(): string
    {
        $organization = $this->organizationName();

        return $organization === null
            ? __('Choose someone in your organization.')
            : __('Choose someone in :organization.', ['organization' => $organization]);
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
                $fail($this->choose());
            }
        };
    }
}
