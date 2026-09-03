<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')->update(['is_public' => true]);
    }

    public function down(): void
    {
        // One-way data backfill; no safe inverse.
    }
};
