<?php

namespace App\Http\Resources;

use App\Models\GitRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A linked repository. Nothing of its connection (no token, no secret).
 *
 * @mixin GitRepository
 */
class GitRepositoryResource extends JsonResource
{
    public static $wrap = null;

    /** @var array{state: string, checks: list<array{name: string, state: string, url: string|null}>}|null */
    private ?array $mainChecks = null;

    /**
     * The checks of its `main` (MainChecksQuery), when the caller read them.
     *
     * @param  array{state: string, checks: list<array{name: string, state: string, url: string|null}>}|null  $checks
     */
    public function withMainChecks(?array $checks): static
    {
        $this->mainChecks = $checks;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->owner,
            'name' => $this->name,
            'full_name' => $this->full_name,
            'html_url' => $this->html_url,
            'clone_url' => $this->clone_url,
            'ssh_url' => $this->ssh_url,
            'default_branch' => $this->default_branch,
            'last_pushed_at' => $this->last_pushed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // The tracker's rule is in place on `main` (MainBranch).
            'protects_main' => (bool) $this->protects_main,
            'main_checks' => $this->mainChecks,
            'open_pull_requests' => $this->whenLoaded('openPullRequests', fn () => GitPullRequestResource::collection($this->openPullRequests)->resolve()),
            'open_pull_requests_count' => $this->whenCounted('openPullRequests'),
        ];
    }
}
