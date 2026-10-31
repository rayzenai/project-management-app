<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The forge's checks (Forgejo Actions runs) on each commit: their overall
 * state (`passed`, `failed`, `running` or `none`; null until looked at),
 * each check's name, state and link, and when the tracker last asked. A pull
 * request keeps its head commit and that commit's checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_commits', function (Blueprint $table): void {
            $table->string('checks_state', 16)->nullable();
            $table->jsonb('checks')->nullable();
            $table->timestamp('checks_checked_at')->nullable();
            $table->index(['checks_state', 'created_at']);
        });

        Schema::table('git_pull_requests', function (Blueprint $table): void {
            $table->string('head_sha', 64)->nullable();
            $table->string('checks_state', 16)->nullable();
            $table->jsonb('checks')->nullable();
            $table->index(['git_repository_id', 'head_sha']);
        });
    }

    public function down(): void
    {
        Schema::table('git_pull_requests', function (Blueprint $table): void {
            $table->dropIndex(['git_repository_id', 'head_sha']);
            $table->dropColumn(['head_sha', 'checks_state', 'checks']);
        });

        Schema::table('git_commits', function (Blueprint $table): void {
            $table->dropIndex(['checks_state', 'created_at']);
            $table->dropColumn(['checks_state', 'checks', 'checks_checked_at']);
        });
    }
};
