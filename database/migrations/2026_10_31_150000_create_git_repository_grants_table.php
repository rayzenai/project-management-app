<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may reach a linked repository on the forge besides its organization's
 * Owners and its maintainers: one grant per person per repository, `read`
 * or `write`. A grant outlives the login that made it (`granted_by` goes
 * null).
 * A repository also records whether the tracker protects its `main` and
 * when its access was last written to the forge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_repository_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('git_repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 5); // read or write
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['git_repository_id', 'member_id']);
            $table->index('member_id');
        });

        Schema::table('git_repositories', function (Blueprint $table): void {
            $table->boolean('protects_main')->default(false);
            $table->timestamp('access_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('git_repositories', function (Blueprint $table): void {
            $table->dropColumn(['protects_main', 'access_synced_at']);
        });

        Schema::dropIfExists('git_repository_grants');
    }
};
