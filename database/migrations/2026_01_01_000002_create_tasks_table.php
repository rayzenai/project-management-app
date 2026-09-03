<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('short_title', 60)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('deadline_at')->nullable();
            $table->string('status')->default('unclear')->index();
            $table->string('priority', 16)->default('medium')->index();
            $table->text('status_note')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->json('source_links')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
