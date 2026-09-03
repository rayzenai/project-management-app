<?php

namespace App\Services;

use App\Models\ProjectActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ProjectActivityRecorder
{
    /**
     * Memoized "yes the table exists" flag. We only cache the positive case —
     * once `project_activities` exists it isn't going away mid-process. Caching
     * the negative case would silently disable activity logging for the rest
     * of the request if the table is created later (which happens during
     * RefreshDatabase: Task rows are seeded before the
     * create_project_activities_table migration runs).
     */
    private static bool $tableExists = false;

    /**
     * Write a single ProjectActivity row.
     *
     * Returns null when the `project_activities` table doesn't exist yet — this
     * happens during early migrations (e.g. the seeder that creates Task rows
     * runs before the `create_project_activities_table` migration). Without
     * this guard, RefreshDatabase test runs blow up before the suite starts.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(
        int $taskId,
        Model $subject,
        string $action,
        string $description,
        ?array $changes = null,
    ): ?ProjectActivity {
        if (! self::tableExists()) {
            return null;
        }

        return ProjectActivity::create([
            'task_id' => $taskId,
            'user_id' => auth()->id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'changes' => $changes,
            'description' => mb_substr($description, 0, 500, 'UTF-8'),
            'is_public' => false,
        ]);
    }

    private static function tableExists(): bool
    {
        if (self::$tableExists) {
            return true;
        }

        return self::$tableExists = Schema::hasTable('project_activities');
    }

    /**
     * Reset the cached table-exists flag. Intended for tests that recreate the
     * schema between runs (e.g. RefreshDatabase).
     */
    public static function flushTableExistsCache(): void
    {
        self::$tableExists = false;
    }

    /**
     * Truncate a string for use inside activity descriptions.
     */
    public static function truncate(?string $value, int $length = 60): string
    {
        $value = (string) $value;

        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length, 'UTF-8').'…';
    }
}
