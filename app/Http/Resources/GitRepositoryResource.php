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
            'open_pull_requests' => $this->whenLoaded('openPullRequests', fn () => GitPullRequestResource::collection($this->openPullRequests)->resolve()),
            'open_pull_requests_count' => $this->whenCounted('openPullRequests'),
        ];
    }
}
