<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's forge username, assigned on their first sign-in to the forge
 * (App\Support\Git\GitUsernames) and never changed after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('git_username', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['git_username']);
            $table->dropColumn('git_username');
        });
    }
};
