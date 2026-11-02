<?php

namespace App\Http\Requests;

use App\Support\InvitePlan;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inviting people, and its preview: whoever may create the organization's
 * people (canCreateMember). A super-admin names the organization; anyone
 * else invites into the organization they administer, and naming another
 * one is refused (403), never quietly switched. Only names, emails and
 * designations are read from `people`: a status or id sent with them is
 * ignored, as InvitePlan rebuilds the plan.
 *
 * @phpstan-import-type InviteInput from InvitePlan
 */
class InvitePeopleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canCreateMember($this->user(), $this->organizationId());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_id' => [
                Rule::requiredIf(fn (): bool => WorkspaceAccess::isSuperAdmin($this->user())),
                'nullable', 'integer', Rule::exists('organizations', 'id'),
            ],
            'people' => ['nullable', 'array', 'max:'.InvitePlan::MAX_PEOPLE],
            'people.*' => ['array'],
            'people.*.name' => ['nullable', 'string', 'max:255'],
            'people.*.email' => ['nullable', 'string', 'max:255'],
            'people.*.designation_id' => ['nullable', 'integer'],
            'without_login' => ['nullable', 'boolean'],
            // The preview's alternative to `people`: the pasted lines.
            'text' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * The organization named (a super-admin must name one), else the one
     * the user administers.
     */
    public function organizationId(): ?int
    {
        if ($this->filled('organization_id')) {
            return $this->integer('organization_id') ?: null;
        }

        return WorkspaceAccess::isSuperAdmin($this->user()) ? null : WorkspaceAccess::officeAdminOrganizationId($this->user());
    }

    /** After validation: authorize() and the rules leave an organization. */
    public function organization(): int
    {
        return (int) $this->organizationId();
    }

    /**
     * @return list<InviteInput>
     */
    public function people(): array
    {
        /** @var array<int, array<string, mixed>> $people */
        $people = $this->validated('people') ?? [];

        return array_values(array_map(fn (array $person): array => [
            'name' => is_string($person['name'] ?? null) ? $person['name'] : null,
            'email' => is_string($person['email'] ?? null) ? $person['email'] : null,
            'designation_id' => is_numeric($person['designation_id'] ?? null) ? (int) $person['designation_id'] : null,
        ], $people));
    }

    /**
     * The preview's people: the pasted `text` when sent, else `people`.
     *
     * @return list<InviteInput>
     */
    public function previewPeople(): array
    {
        $text = $this->validated('text');

        return is_string($text) ? InvitePlan::parse($text) : $this->people();
    }

    public function withoutLogin(): bool
    {
        return $this->boolean('without_login');
    }
}
