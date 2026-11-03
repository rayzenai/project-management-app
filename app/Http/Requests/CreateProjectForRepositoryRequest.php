<?php

namespace App\Http\Requests;

use App\Models\GitConnection;
use App\Models\Program;
use App\Support\ProjectKeys;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "New project for it" on a forge repository no project links: whoever may
 * create projects and administers the connection's organization
 * (WorkspaceAccess::canSeeUnlinkedRepositories). The project lands in that
 * organization; its key follows the project form's rules (1 to 10 capital
 * letters, free in the organization), and its program, if any, is one of
 * that organization's.
 */
class CreateProjectForRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->gitConnection();

        return $connection !== null
            && WorkspaceAccess::canCreateProject($this->user())
            && WorkspaceAccess::canSeeUnlinkedRepositories($this->user(), $connection);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'regex:/^[A-Z]{1,10}$/'],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('key'))) {
            $this->merge(['key' => strtoupper(trim($this->input('key')))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $organizationId = (int) $this->gitConnection()?->organization_id;

            if ($this->filled('key') && ProjectKeys::isTaken($organizationId, (string) $this->input('key'))) {
                $validator->errors()->add('key', 'That key is already used in this organization.');
            }

            if ($this->filled('program_id') && (int) Program::query()->whereKey($this->integer('program_id'))->value('organization_id') !== $organizationId) {
                $validator->errors()->add('program_id', 'That program belongs to another organization.');
            }
        });
    }

    /**
     * The new project's fields, as validated.
     *
     * @return array{title: string, key: ?string, program_id: ?int}
     */
    public function projectAttributes(): array
    {
        $key = $this->validated('key');
        $programId = $this->validated('program_id');

        return [
            'title' => trim((string) $this->validated('title')),
            'key' => is_string($key) && $key !== '' ? $key : null,
            'program_id' => is_numeric($programId) ? (int) $programId : null,
        ];
    }

    public function gitConnection(): ?GitConnection
    {
        $connection = $this->route('connection');

        return $connection instanceof GitConnection ? $connection : null;
    }

    public function forgeId(): int
    {
        return (int) $this->route('forgeId');
    }
}
