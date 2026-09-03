<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reminder_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('window'); // heads_up|due_today|overdue
            $table->date('reference_date');
            $table->timestamp('sent_at');
            $table->unique(['task_id', 'window', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminder_logs');
    }
};
