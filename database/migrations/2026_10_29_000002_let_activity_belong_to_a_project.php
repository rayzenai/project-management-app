<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activity now always names its project, and may concern the project itself
 * rather than one of its tasks (its team changing hands, say). Existing rows
 * get their task's project. `changes` moves to jsonb while the table is
 * being touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_activities', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['project_id', 'created_at']);
        });

        DB::statement('UPDATE project_activities SET project_id = (SELECT project_id FROM tasks WHERE tasks.id = project_activities.task_id)');

        Schema::table('project_activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE project_activities ALTER COLUMN changes TYPE jsonb USING changes::jsonb');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE project_activities ALTER COLUMN changes TYPE json USING changes::json');
        }

        DB::table('project_activities')->whereNull('task_id')->delete();

        Schema::table('project_activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
