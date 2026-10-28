<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app timezone moved from UTC to Asia/Kathmandu. Timestamp columns are
 * `timestamp without time zone`, so values written before the move are UTC
 * wall-clock times that would now read 5h45m early. Shift them once.
 * Date-only columns (deadlines, due dates) carry no time and are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift('UTC', 'Asia/Kathmandu');
    }

    public function down(): void
    {
        $this->shift('Asia/Kathmandu', 'UTC');
    }

    private function shift(string $from, string $to): void
    {
        // SQLite (the test suite) has no zone-aware timestamps to fix.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columns = DB::select(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND data_type = 'timestamp without time zone'",
        );

        $grammar = DB::getQueryGrammar();

        foreach ($columns as $column) {
            $table = $grammar->wrapTable($column->table_name);
            $col = $grammar->wrap($column->column_name);

            DB::statement(
                "UPDATE {$table} SET {$col} = ({$col} AT TIME ZONE ?) AT TIME ZONE ? WHERE {$col} IS NOT NULL",
                [$from, $to],
            );
        }
    }
};
