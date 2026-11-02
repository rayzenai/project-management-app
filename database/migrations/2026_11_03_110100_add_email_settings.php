<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email beside the in-app notifications. Each person turns each kind on or
 * off (right away is the existing email_notifications; the morning email and
 * the weekly brief are new, all on), and every notification records when
 * the right-away email dealt with it (SendEmailUpdates). Everything already
 * there counts as dealt with, so nothing old is emailed after the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('email_morning')->default(true);
            $table->boolean('email_weekly')->default(true);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('email_handled_at')->nullable();
            $table->index(['notifiable_type', 'notifiable_id', 'email_handled_at'], 'notifications_email_pending_index');
        });

        DB::table('notifications')->update(['email_handled_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_email_pending_index');
            $table->dropColumn('email_handled_at');
        });

        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['email_morning', 'email_weekly']);
        });
    }
};
