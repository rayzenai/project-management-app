<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organization a super-admin's workspace is switched to (the sidebar's
 * projects, and the organization the office pages open on); null is all of
 * them. Kept on the user so it survives signing out. Deleting the
 * organization switches back to all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('workspace_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_organization_id');
        });
    }
};
