<?php

namespace App\Services\Workspace;

use App\Models\WorkspaceNote;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateWorkspaceNoteService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(int $userId, array $attributes): ServiceResult
    {
        $body = trim((string) ($attributes['body'] ?? ''));
        if ($body === '') {
            return ServiceResult::failure('Note body is required.', 422);
        }

        try {
            $note = DB::transaction(function () use ($userId, $attributes, $body): WorkspaceNote {
                $count = WorkspaceNote::query()->where('user_id', $userId)->count();

                return WorkspaceNote::create([
                    'user_id' => $userId,
                    'title' => $this->normalizeTitle($attributes['title'] ?? null),
                    'body' => $body,
                    'color' => WorkspaceNote::COLORS[$count % count(WorkspaceNote::COLORS)],
                    'position_x' => 48 + ($count % 6) * 36,
                    'position_y' => 48 + ($count % 6) * 36,
                ]);
            });

            return ServiceResult::success($note, 'Note saved.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }

    private function normalizeTitle(mixed $title): ?string
    {
        $title = trim((string) ($title ?? ''));

        return $title === '' ? null : $title;
    }
}
