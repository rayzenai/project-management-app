<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The right-away email's scan (SendEmailUpdates, every minute): the
 * notifications no email has dealt with yet, from the last day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['email_handled_at', 'created_at'], 'notifications_email_scan_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_email_scan_index');
        });
    }
};
