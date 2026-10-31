<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's structure: its own list of designations (Executive
 * Director, Director, Software Engineer, ...), each person's designation
 * and the one person they report to, which the organogram draws and which
 * decides who sees whose attendance and work. `attendance_exempt` keeps a
 * person out of the expected count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('rank')->default(100); // smaller is more senior
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('members')->nullOnDelete();
            $table->boolean('attendance_exempt')->default(false);

            $table->index('reports_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reports_to_id');
            $table->dropConstrainedForeignId('designation_id');
            $table->dropColumn('attendance_exempt');
        });

        Schema::dropIfExists('designations');
    }
};
