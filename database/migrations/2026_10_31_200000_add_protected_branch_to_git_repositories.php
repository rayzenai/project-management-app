<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The branch the tracker protects (MainBranch), fixed when a repository is
 * linked: `default_branch` follows the forge, so a forge admin who made
 * another branch the default moved the protection with it. Linked
 * repositories keep the default branch they have now (`main` when the
 * forge named none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_repositories', function (Blueprint $table): void {
            $table->string('protected_branch')->nullable();
        });

        DB::table('git_repositories')->update([
            'protected_branch' => DB::raw("COALESCE(NULLIF(default_branch, ''), 'main')"),
        ]);

        Schema::table('git_repositories', function (Blueprint $table): void {
            $table->string('protected_branch')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('git_repositories', function (Blueprint $table): void {
            $table->dropColumn('protected_branch');
        });
    }
};
