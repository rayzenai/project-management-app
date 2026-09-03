<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('status_updated_at');
        });

        DB::table('tasks')->where('status', 'late')->update(['status' => 'in_progress']);
        DB::table('tasks')->where('status', 'done_late')->update(['status' => 'done']);

        DB::table('tasks')
            ->where('status', 'done')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('status_updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });
    }
};
