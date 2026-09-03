<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body');
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->integer('position')->default(0);
            $table->date('due_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'user_id', 'position']);
            $table->index(['user_id', 'due_at', 'is_done']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtasks');
    }
};
