<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private to-dos ("Just for me"): a person's own items, with a title, an
 * optional date and done or not. Only their owner reads or changes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'done_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_todos');
    }
};
