<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forge repositories linked to projects, and the branches, commits and pull
 * requests seen in them. `task_git_links` ties each of those to the tasks
 * whose keys (TP-12) it mentions. A link is polymorphic, so it also names
 * its repository: links go with the repository, however it is deleted.
 * Hashes are up to 64 characters, for SHA-256 repositories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_repositories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('git_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('forge_id');
            $table->string('owner');
            $table->string('name');
            $table->string('full_name');
            $table->string('html_url');
            $table->string('clone_url');
            $table->string('ssh_url');
            $table->string('default_branch');
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            // One forge repository is linked to at most one project.
            $table->unique(['git_connection_id', 'forge_id']);
            $table->index('project_id');
        });

        Schema::create('git_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('git_repository_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('head_sha', 64)->nullable();
            $table->timestamps();

            $table->unique(['git_repository_id', 'name']);
        });

        Schema::create('git_commits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('git_repository_id')->constrained()->cascadeOnDelete();
            $table->string('sha', 64);
            $table->text('message');
            $table->string('author_name');
            $table->string('author_username')->nullable();
            $table->string('url');
            $table->timestamp('committed_at');
            $table->timestamp('unreachable_at')->nullable();
            $table->timestamps();

            $table->unique(['git_repository_id', 'sha']);
        });

        Schema::create('git_pull_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('git_repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('state'); // open, closed or merged
            $table->string('url');
            $table->string('head_branch');
            $table->string('author_username')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('merged_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // The forge's updated_at of the snapshot stored: an older one never overwrites it.
            $table->timestamp('forge_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['git_repository_id', 'number']);
        });

        Schema::create('task_git_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('git_repository_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['task_id', 'linkable_type', 'linkable_id']);
            $table->index(['linkable_type', 'linkable_id']);
            $table->index('git_repository_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_git_links');
        Schema::dropIfExists('git_pull_requests');
        Schema::dropIfExists('git_commits');
        Schema::dropIfExists('git_branches');
        Schema::dropIfExists('git_repositories');
    }
};
