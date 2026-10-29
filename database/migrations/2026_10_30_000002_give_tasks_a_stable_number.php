<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task codes (SG-12) end up in commit messages, which never change, so the
 * number half of the code becomes a real column that is assigned once and
 * never edited. The plan tracker's own item_number stays in metadata and
 * stays editable. Existing tasks keep their item number when it is a unique
 * one from 1 to 100000 (earliest wins a duplicate); the rest are numbered
 * after the highest, in id order, or take the lowest free number once that
 * would pass the column's limit. Soft-deleted tasks count, and the project's
 * last_task_number only ever goes up, so no number is reused even after the
 * trash is purged.
 */
return new class extends Migration
{
    /**
     * The highest legacy item number a task keeps as its number. Plan items
     * are numbered in the hundreds, so anything larger is legacy noise and is
     * numbered in sequence instead; this also keeps the counter far from the
     * limit of a Postgres integer column (2147483647).
     */
    private const int MAX_CLAIMED_ITEM = 100000;

    /** The highest value of a Postgres integer column. */
    private const int MAX_NUMBER = 2147483647;

    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedInteger('number')->nullable()->after('project_id');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedInteger('last_task_number')->default(0);
        });

        $projectIds = DB::table('tasks')->distinct()->pluck('project_id');

        foreach ($projectIds as $projectId) {
            $tasks = DB::table('tasks')->where('project_id', $projectId)->orderBy('id')->get(['id', 'metadata']);

            $claimed = [];
            $assigned = [];

            foreach ($tasks as $task) {
                $meta = json_decode((string) $task->metadata, true);
                $item = is_array($meta) ? ($meta['item_number'] ?? null) : null;

                if (is_numeric($item) && $item >= 1 && $item <= self::MAX_CLAIMED_ITEM && ! isset($claimed[(int) $item])) {
                    $claimed[(int) $item] = true;
                    $assigned[$task->id] = (int) $item;
                }
            }

            $next = $claimed === [] ? 1 : max(array_keys($claimed)) + 1;

            foreach ($tasks as $task) {
                if (! isset($assigned[$task->id])) {
                    if ($next > self::MAX_NUMBER) {
                        $next = 1;
                    }

                    while (isset($claimed[$next])) {
                        $next++;
                    }

                    $assigned[$task->id] = $next;
                    $claimed[$next] = true;
                    $next++;
                }

                DB::table('tasks')->where('id', $task->id)->update(['number' => $assigned[$task->id]]);
            }

            DB::table('projects')->where('id', $projectId)->update(['last_task_number' => $assigned === [] ? 0 : max($assigned)]);
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedInteger('number')->nullable(false)->change();
            $table->unique(['project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'number']);
            $table->dropColumn('number');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('last_task_number');
        });
    }
};
