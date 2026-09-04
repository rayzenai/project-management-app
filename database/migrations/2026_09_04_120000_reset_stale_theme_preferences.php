<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The appearance catalogue shrank to system / light / dark with a single font
 * family. Preferences saved under the earlier catalogue (terminal-noir, paper,
 * glass, font overrides) no longer resolve, so reset them to the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_preferences')
            ->whereNotIn('theme', ['system', 'light', 'dark'])
            ->update(['theme' => 'system']);

        DB::table('user_preferences')
            ->whereNotNull('font_override')
            ->update(['font_override' => null]);
    }

    public function down(): void
    {
        // Irreversible: the old catalogue entries no longer exist.
    }
};
