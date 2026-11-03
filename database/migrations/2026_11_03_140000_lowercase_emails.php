<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emails are stored trimmed and lowercase from now on (the User and Member
 * mutators), and sign-in and password links look them up that way, so the
 * ones stored before are lowercased here. Two rows of a table whose emails
 * differ only in case (or spaces) are left as they are, with a warning
 * naming their ids: which one is the person's is for a person to decide,
 * never merged or deleted here. Lowercased in PHP, so SQLite and Postgres
 * agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'members'] as $table) {
            $this->lowercase($table);
        }
    }

    /**
     * The stored case is not kept anywhere, so there is nothing to undo.
     */
    public function down(): void {}

    private function lowercase(string $table): void
    {
        $byAddress = DB::table($table)
            ->whereNotNull('email')
            ->orderBy('id')
            ->get(['id', 'email'])
            ->groupBy(fn (object $row): string => mb_strtolower(trim((string) $row->email)));

        foreach ($byAddress as $address => $rows) {
            if ($rows->pluck('email')->unique()->count() > 1) {
                Log::warning("Emails in {$table} that differ only in case were left as they are, ids ".$rows->pluck('id')->implode(', ').'.');

                continue;
            }

            if ($rows->first()?->email !== $address) {
                DB::table($table)->whereIn('id', $rows->pluck('id'))->update(['email' => $address]);
            }
        }
    }
};
