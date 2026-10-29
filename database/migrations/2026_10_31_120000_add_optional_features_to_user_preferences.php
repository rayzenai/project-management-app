<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task notes and contacts are features most people never need, so each
 * person turns them on in Settings. Off until then; turning one off hides it
 * and keeps its data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('task_notes_enabled')->default(false);
            $table->boolean('contacts_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['task_notes_enabled', 'contacts_enabled']);
        });
    }
};
