<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programs: a named collection of projects in one organization, with one
 * program manager. `manager_id` is nullable only so pruning a trashed member
 * never fails (a team loses its leader the same way, through member_team's
 * cascade); every request still requires a manager. A project is in at most
 * one program, and deleting a program lets its projects go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'name']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('program_id')->nullable()->index()->constrained('programs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['program_id']);
            $table->dropIndex(['program_id']);
            $table->dropColumn('program_id');
        });

        Schema::dropIfExists('programs');
    }
};
