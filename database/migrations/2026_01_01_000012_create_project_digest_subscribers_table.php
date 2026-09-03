<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_digest_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->json('categories')->nullable();
            $table->string('frequency')->default('weekly');
            $table->string('confirmation_token')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('unsubscribe_token')->unique();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_digest_subscribers');
    }
};
