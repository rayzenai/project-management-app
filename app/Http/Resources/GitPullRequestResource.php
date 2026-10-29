<?php

namespace App\Http\Resources;

use App\Models\GitPullRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pull request of a linked repository, as the Code tab and a task's Code
 * section show it. Its body stays on the forge.
 *
 * @mixin GitPullRequest
 */
class GitPullRequestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'state' => $this->state,
            'url' => $this->url,
            'head_branch' => $this->head_branch,
            'author_username' => $this->author_username,
            'repository' => $this->whenLoaded('repository', fn () => $this->repository->full_name),
            'opened_at' => $this->opened_at->toIso8601String(),
            'merged_at' => $this->merged_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
