<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per summary email a person got on a day (the morning email,
 * kind `morning`). The unique key is the claim: a run inserts the row before
 * the email goes, so no day is ever sent twice, and the newest row says
 * where "new since the last one" starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->date('sent_on');
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'kind', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_digests');
    }
};
