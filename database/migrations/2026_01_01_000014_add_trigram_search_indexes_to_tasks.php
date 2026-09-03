<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres-only fuzzy search support. The workspace command palette relies on
 * the pg_trgm extension's similarity() function and GIN trigram indexes for
 * fast ILIKE matching. On any non-Postgres driver this migration is a no-op,
 * keeping the schema portable to MySQL/SQLite (the test suite runs on SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS tasks_title_trgm_idx ON tasks USING gin (title gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS tasks_status_note_trgm_idx ON tasks USING gin (status_note gin_trgm_ops)');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS tasks_title_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS tasks_status_note_trgm_idx');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
