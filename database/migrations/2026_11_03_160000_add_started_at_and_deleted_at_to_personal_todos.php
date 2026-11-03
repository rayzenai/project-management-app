<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The top bar's to-do list: `started_at` says a to-do is being worked on
 * ("Doing" while it is not done), and `deleted_at` keeps a deleted one for
 * 7 days, so its Undo can bring it back (workspace:prune-trashed removes it
 * after that).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_todos', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('due_on');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('personal_todos', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('started_at');
        });
    }
};
