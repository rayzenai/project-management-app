<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks by who created them: quick add's last project (on every page) and My
 * work's "You asked others" both filter on it. Postgres gives a foreign key
 * no index of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
        });
    }
};
