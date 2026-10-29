<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('base_url');
            $table->string('owner');
            $table->text('token');
            $table->text('webhook_secret')->nullable();
            $table->unsignedBigInteger('webhook_id')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One forge organization belongs to one office. base_url is stored normalized.
        DB::statement('create unique index git_connections_forge_owner_unique on git_connections (base_url, lower(owner))');
    }

    public function down(): void
    {
        Schema::dropIfExists('git_connections');
    }
};
