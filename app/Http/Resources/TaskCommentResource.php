<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin TaskComment
 */
class TaskCommentResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Author members keyed by `user_id`, populated by {@see self::preload()} so
     * a paginated thread resolves all authors in one query instead of one per row.
     *
     * @var array<int, Member>
     */
    private static array $authorsByUserId = [];

    /**
     * Mention members keyed by `id`, populated by {@see self::preload()}.
     *
     * @var array<int, Member>
     */
    private static array $mentionsById = [];

    /**
     * Transient model attribute set by {@see self::preload()} to mark a comment
     * as covered by the current preload. A row consults the static maps only
     * when this flag is present on its model — so a stale preload from an
     * earlier render (same long-lived process) never leaks into an un-preloaded
     * single-item store/update response, even if database ids are reused.
     */
    private const PRELOAD_FLAG = '__comment_resource_preloaded';

    /**
     * Bulk-load every author and mentioned member across a page of comments into
     * static maps so {@see self::toArray()} reads from memory instead of querying
     * per row. The maps are replaced (not merged) on each call to avoid
     * cross-request leakage, and each comment is tagged so only the rows in this
     * call trust the maps. Single-item store/update responses skip preload and
     * fall back to the per-row query path.
     *
     * @param  Collection<int, TaskComment>  $comments
     */
    public static function preload(Collection $comments): void
    {
        $userIds = $comments->pluck('user_id')->filter()->unique()->values()->all();

        $mentionIds = $comments
            ->flatMap(fn (TaskComment $comment): array => $comment->mentioned_member_ids ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        self::$authorsByUserId = $userIds === []
            ? []
            : Member::query()->whereIn('user_id', $userIds)->get(['id', 'name', 'user_id'])->keyBy('user_id')->all();

        self::$mentionsById = $mentionIds === []
            ? []
            : Member::query()->whereIn('id', $mentionIds)->get(['id', 'name'])->keyBy('id')->all();

        $comments->each(fn (TaskComment $comment) => $comment->setAttribute(self::PRELOAD_FLAG, true));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $preloaded = (bool) $this->resource->getAttribute(self::PRELOAD_FLAG);

        $authorMember = $preloaded
            ? (self::$authorsByUserId[$this->user_id] ?? null)
            : Member::query()->where('user_id', $this->user_id)->first();

        $mentionedIds = $this->mentioned_member_ids ?? [];

        $mentionMembers = $preloaded
            ? collect(self::$mentionsById)
            : Member::query()->whereIn('id', $mentionedIds)->get(['id', 'name'])->keyBy('id');

        $mentions = collect($mentionedIds)
            ->map(fn ($id) => $mentionMembers->get($id))
            ->filter()
            ->map(fn (Member $m) => ['member_id' => $m->id, 'name' => $m->name])
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'mentions' => $mentions,
            'author' => [
                'member_id' => $authorMember?->id,
                'name' => $authorMember->name ?? $this->user?->name,
            ],
            'can_edit' => $request->user()?->id === $this->user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
