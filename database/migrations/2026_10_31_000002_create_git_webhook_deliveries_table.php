<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every signed forge webhook delivery, stored once by the forge's delivery
 * UUID so a replay is never applied twice. `processed_at` is set once it is
 * applied (or when there is nothing to apply); `error` keeps the last
 * failure while the queue retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('git_connection_id')->constrained()->cascadeOnDelete();
            $table->uuid('delivery')->unique();
            $table->string('event');
            $table->jsonb('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->index('git_connection_id');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_webhook_deliveries');
    }
};
