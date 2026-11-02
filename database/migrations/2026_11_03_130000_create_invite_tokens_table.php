<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `invites` password broker's tokens (config/auth.php), shaped like
 * password_reset_tokens but a table of their own, so a 7-day invite link
 * and a 60-minute reset link never share a row or an expiry. And when a
 * person was last sent an invite (PasswordLinkService::sendInvite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('invited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('invited_at');
        });

        Schema::dropIfExists('invite_tokens');
    }
};
