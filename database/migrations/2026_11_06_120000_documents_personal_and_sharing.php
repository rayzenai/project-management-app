<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Personal documents and sharing
 * (docs/superpowers/specs/2026-11-06-documents-workspace-design.md). A
 * document belongs to a project (project_id) or to the person who made it
 * (owner_id); deleting a login that owns one is refused, so a personal
 * document always names its owner. organization_access opens it to
 * everyone in its organization; document_shares give it to chosen people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
            $table->foreignId('owner_id')->nullable()->after('project_id')->constrained('users')->restrictOnDelete();
            $table->string('organization_access', 8)->nullable()->after('owner_id');

            $table->index(['owner_id', 'deleted_at', 'updated_at']);
        });

        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 8);
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_shares');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'deleted_at', 'updated_at']);
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn('organization_access');
        });
    }
};
