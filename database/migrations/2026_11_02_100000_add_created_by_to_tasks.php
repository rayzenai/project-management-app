<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who created each task (WorkspaceAccess::canDeleteTask lets them delete
 * it). Existing tasks take it from their "Created the task" activity row;
 * a task made by the system, or whose creator's login is gone, has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Each task without a creator takes the user of its "Created the task"
     * activity row. Safe to run again.
     */
    public function backfill(): void
    {
        DB::statement(<<<'SQL'
            UPDATE tasks SET created_by = (
                SELECT project_activities.user_id FROM project_activities
                WHERE project_activities.subject_type = 'task'
                    AND project_activities.subject_id = tasks.id
                    AND project_activities.action = 'created'
                ORDER BY project_activities.id
                LIMIT 1
            )
            WHERE created_by IS NULL
            SQL);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
