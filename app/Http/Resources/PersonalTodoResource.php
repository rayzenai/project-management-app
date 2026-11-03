<?php

namespace App\Http\Resources;

use App\Models\PersonalTodo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A private to-do, as its owner (the only one who ever reads it) sees it.
 *
 * @mixin PersonalTodo
 */
class PersonalTodoResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'due_on' => $this->due_on?->toDateString(),
            'started_at' => $this->started_at?->toIso8601String(),
            'done_at' => $this->done_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
