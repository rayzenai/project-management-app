<?php

namespace App\Http\Requests;

use App\Models\GitConnection;
use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EnableGitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('organization') instanceof Organization
            && WorkspaceAccess::canManageGit($this->user());
    }

    /**
     * The bot token travels to the forge on every call, so plain http is
     * allowed only for a forge on this machine (a local proof).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_url' => ['required', 'url:http,https', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $parts = parse_url((string) $value);

                if (isset($parts['user']) || isset($parts['pass'])) {
                    $fail("The forge address can't contain a user name or password.");

                    return;
                }
                if (isset($parts['query']) || isset($parts['fragment']) || str_contains((string) $value, '?') || str_contains((string) $value, '#')) {
                    $fail("The forge address can't have a query or fragment.");

                    return;
                }

                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

                if ($scheme === 'http' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                    $fail('Use https for the forge address. Plain http is only for 127.0.0.1, localhost or ::1.');
                }
            }],
            'owner' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'token' => ['required', 'string', 'max:512'],
        ];
    }

    /**
     * One forge organization belongs to one office: another office already
     * connected to the same owner on the same forge is refused. Re-enabling
     * this office's own connection is fine.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Organization $organization */
            $organization = $this->route('organization');
            $owner = (string) $this->input('owner');
            $baseUrl = GitConnection::normalizeBaseUrl((string) $this->input('base_url'));

            $taken = GitConnection::query()
                ->where('organization_id', '!=', $organization->id)
                ->whereRaw('lower(owner) = ?', [mb_strtolower($owner)])
                ->pluck('base_url')
                ->contains(fn (string $url): bool => GitConnection::normalizeBaseUrl($url) === $baseUrl);

            if ($taken) {
                $validator->errors()->add('owner', "{$owner} is already connected to another office.");
            }
        }];
    }
}
