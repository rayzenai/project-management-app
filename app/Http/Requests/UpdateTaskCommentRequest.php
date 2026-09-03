<?php

namespace App\Http\Requests;

use App\Models\TaskComment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $comment = $this->route('comment');

        if (! $user || ! $comment) {
            return false;
        }

        if (! $comment instanceof TaskComment) {
            $comment = TaskComment::query()->whereKey($comment)->first();
        }

        return $comment !== null && $user->getKey() === $comment->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
