<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote a commit (the user whose forge username it names) and how many
 * lines it changed, counted from its diff by `git:commit-stats`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_commits', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('git_repository_id')->constrained()->nullOnDelete();
            $table->boolean('is_merge')->default(false);
            $table->unsignedInteger('files_changed')->nullable();
            $table->unsignedInteger('additions')->nullable();
            $table->unsignedInteger('deletions')->nullable();

            foreach (['code_added', 'code_removed', 'comment_added', 'comment_removed', 'blank_added', 'blank_removed', 'docs_added', 'docs_removed', 'generated_lines'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }

            $table->string('stats_status', 16)->nullable();
            $table->timestamp('stats_computed_at')->nullable();

            $table->index(['user_id', 'committed_at']);
            $table->index('stats_computed_at');
        });
    }

    public function down(): void
    {
        Schema::table('git_commits', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'committed_at']);
            $table->dropIndex(['stats_computed_at']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'is_merge', 'files_changed', 'additions', 'deletions',
                'code_added', 'code_removed', 'comment_added', 'comment_removed',
                'blank_added', 'blank_removed', 'docs_added', 'docs_removed',
                'generated_lines', 'stats_status', 'stats_computed_at',
            ]);
        });
    }
};
