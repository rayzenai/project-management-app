<?php

namespace App\Services\Workspace;

use App\Support\ServiceResult;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class RestoreWorkspaceModel
{
    public function execute(Model $model): ServiceResult
    {
        try {
            if (! method_exists($model, 'trashed') || ! method_exists($model, 'restore')) {
                return ServiceResult::failure('This record cannot be restored.', 422);
            }

            if (! $model->trashed()) {
                return ServiceResult::success($model, 'Already restored.');
            }

            $this->ensureUniqueSlug($model);
            $model->restore();

            return ServiceResult::success($model->fresh(), 'Restored.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }

    /**
     * Defensive: currently unreachable for existing models. `tasks`/`teams`
     * slugs carry a global unique index that also covers trashed rows, and the
     * create-paths skip past trashed slugs — so a restored row's slug is always
     * free. Retained for a future partial-index schema or any slugged model
     * whose slug uniqueness is not enforced at the database level.
     */
    private function ensureUniqueSlug(Model $model): void
    {
        if (empty($model->slug)) {
            return;
        }

        $base = $model->slug;
        $i = 1;
        while ($model->newQuery()
            ->where('slug', $model->slug)
            ->whereKeyNot($model->getKey())
            ->exists()
        ) {
            $model->slug = $base.'-'.(++$i);
        }
    }
}
